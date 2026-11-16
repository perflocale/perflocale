<?php
/**
 * The resync of one (source, language) pair.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Resync;

use PerfLocale\Automation\ChangeService;
use PerfLocale\Automation\Policy;
use PerfLocale\Automation\PostTarget;
use PerfLocale\Concurrency\Lock;
use PerfLocale\Database\Repository\PendingChangeRepository;
use PerfLocale\Database\Repository\ResyncRepository;
use PerfLocale\Enum\TranslationStatus;
use PerfLocale\Helper;
use PerfLocale\MachineTranslation\ContextSettings;
use PerfLocale\MachineTranslation\KeywordList;
use PerfLocale\MachineTranslation\TranslationService;
use PerfLocale\Settings;
use PerfLocale\Translation\ContentSync;
use PerfLocale\Translation\Fields\FieldMap;
use PerfLocale\Util\Scalar;
use PerfLocale\Util\StateHash;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the resync steps for one pair as the current user; nothing is written outside the change service and the
 * recorder. A dry run takes the same steps without writing or sending. A pair the job's clock stops answers
 * `deferred` and keeps its machine outputs, so no provider request is paid twice.
 *
 * @phpstan-import-type StateRow from ResyncRepository
 * @phpstan-import-type LiveRead from PostTarget
 */
final class PairRunner {

	/** The state kinds whose `complete` column says whether the sync left work for a person. */
	private const HOW_WITH_COMPLETENESS = [ SyncRecorder::HOW_APPLIED, SyncRecorder::HOW_FILLED, SyncRecorder::HOW_UNCHANGED, SyncRecorder::HOW_BOOTSTRAP ];

	/** Most revisions one pair reads in automatic_revision(). */
	private const BASIS_REVISIONS = 500;

	/** Joins a keyword-list field key and a keyword's position in a request index. */
	private const LIST_PIECE = "\x1F";

	/** Seconds one pair spends in automatic_revision(). */
	private const BASIS_SECONDS = 2.0;

	/** Revisions whose text sizes one statement of that walk reads. */
	private const BASIS_PAGE = 20;

	/** Bytes of revision text one read of that walk asks for (a larger revision is read on its own). */
	private const BASIS_BATCH_BYTES = 1048576;

	/**
	 * Resync one pair; its kept machine outputs are settled once the outcome no longer needs them.
	 *
	 * @param int        $source_id   The source post (the default-language member of its group).
	 * @param int        $language_id The translation's language.
	 * @param RunOptions $o           The run's options.
	 * @return Outcome
	 */
	public static function run( int $source_id, int $language_id, RunOptions $o ): Outcome {
		$kept    = [
			'key'    => '',
			'chunks' => 0,
		];
		$outcome = self::pair( $source_id, $language_id, $o, $kept );

		UnitTranslator::settle( $kept, $outcome->status );

		return $outcome;
	}

	/**
	 * Step 4 on a page's batched read: the outcome when the pair stops there without a write (`unchanged` or
	 * `outdated`), else null and the caller runs run(), which reads everything again. Rights and scope are asked live.
	 *
	 * @param int                            $source_id   The source post (the group's default-language member, by the page's read).
	 * @param int                            $language_id The translation's language.
	 * @param RunOptions                     $o           The run's options.
	 * @param \WP_Post                       $source      The source, as the page read it.
	 * @param \WP_Post|null                  $translation The translation the page's links name, as the page read it.
	 * @param array<string, int|string>|null $state       The translation's state hash columns, null when it has no state row.
	 * @phpstan-param StateRow|null $state
	 * @param callable                       $base_keys   `function( int $base_id ): ?array`, the base's key list and `seen` map (null when it could not be read).
	 * @phpstan-param callable(int): (array{keys: list<string>, seen: array<string, string>|null}|null) $base_keys
	 * @return Outcome|null
	 */
	public static function recorded_on_page( int $source_id, int $language_id, RunOptions $o, \WP_Post $source, ?\WP_Post $translation, ?array $state, callable $base_keys ): ?Outcome {
		if ( null === $state || ! $translation instanceof \WP_Post || '' !== $state['intent_at'] || ! Store::available() || ! ChangeService::available() ) {
			return null;
		}

		$translation_id = (int) $translation->ID;
		$langs          = \PerfLocale\Plugin::get_instance()->lang_repo();
		$language       = $langs->find( $language_id );
		$default        = $langs->get_default();
		$default_slug   = Scalar::str( Scalar::prop( $default, 'slug' ) );
		$target_slug    = Scalar::str( Scalar::prop( $language, 'slug' ) );

		if ( null === $language || null === $default || '' === $default_slug || '' === $target_slug || $default_slug === $target_slug ) {
			return null;
		}

		if ( $source->ID !== $source_id || in_array( $source->post_status, [ 'trash', 'auto-draft' ], true ) || 'trash' === $translation->post_status || '' === $translation->post_content ) {
			return null;
		}

		// Recorded source state first, so a changed pair asks rights and scope only once, in run().
		$source_hash = Store::content_hash( $source->post_title, $source->post_content, $source->post_excerpt );

		if ( $state['translation_id'] !== $translation_id || $state['source_id'] !== $source_id || $state['language_id'] !== $language_id || $state['base_id'] <= 0 || $state['source_hash'] !== $source_hash ) {
			return null;
		}

		if ( ! Helper::user_can_edit_object( $translation_id ) || ! \PerfLocale\Util\ObjectPermission::read_text( $source ) ) {
			return null;
		}

		if ( 'publish' !== $source->post_status && ! $o->per_post ) {
			return null;
		}

		$args = [
			'trigger'      => $o->trigger,
			'language_ids' => [ $language_id ],
			'user_id'      => get_current_user_id(),
		];

		if ( ! Scope::includes( $source, $args ) ) {
			return null;
		}

		$fields_scoped = $o->fields && Scope::fields_included( $args );
		$base          = $fields_scoped ? $base_keys( $state['base_id'] ) : [
			'keys' => [],
			'seen' => null,
		];

		if ( null === $base || ( $fields_scoped && Store::digest_for_keys( $base['keys'], PostTarget::field_values( $source_id, $base['keys'] ) ) !== $state['fields_hash'] ) ) {
			return null;
		}

		// A field that became eligible with a value changed since the base was recorded is new work, and an unread source no answer: run() decides.
		if ( $fields_scoped && false !== FieldDrift::pair_added( $base['keys'], $base['seen'], $source, $translation_id ) ) {
			return null;
		}

		// A per-post run that may clear a stale flag takes run()'s path.
		if ( $o->per_post && ! $o->dry_run && 1 === $state['complete'] && SyncRecorder::QUALITY_REVISION !== $state['base_quality'] ) {
			return null;
		}

		$outdated = 0 === $state['complete'] && in_array( $state['how'], self::HOW_WITH_COMPLETENESS, true )
			&& TranslationStatus::NeedsUpdate->value === ( \PerfLocale\Plugin::get_instance()->group_repo()->get_link_state( $translation_id, $language_id )['status'] ?? '' );

		return new Outcome( $outdated ? 'outdated' : 'unchanged', 'recorded', $translation_id );
	}

	/**
	 * Resync one pair: the steps of run().
	 *
	 * @param int        $source_id   The source post (the default-language member of its group).
	 * @param int        $language_id The translation's language.
	 * @param RunOptions $o           The run's options.
	 * @param array      $kept        Set to the pair's kept machine outputs, when any.
	 * @phpstan-param array{key: string, chunks: int} $kept
	 * @return Outcome
	 */
	private static function pair( int $source_id, int $language_id, RunOptions $o, array &$kept ): Outcome {
		if ( ! Store::available() || ! ChangeService::available() ) {
			return new Outcome( 'unavailable' );
		}

		// Read before anything else: an import that runs from here on refuses this pair's write; none may run now.
		$import_generation = ChangeService::import_fence();

		if ( null === $import_generation ) {
			return new Outcome( 'busy', 'import' );
		}

		$plugin   = \PerfLocale\Plugin::get_instance();
		$langs    = $plugin->lang_repo();
		$language = $langs->find( $language_id );
		$default  = $langs->get_default();

		$default_slug = Scalar::str( Scalar::prop( $default, 'slug' ) );
		$target_slug  = Scalar::str( Scalar::prop( $language, 'slug' ) );

		if ( null === $language || null === $default || '' === $default_slug || '' === $target_slug || $default_slug === $target_slug ) {
			return new Outcome( 'no_source', 'language' );
		}

		$source = get_post( $source_id );

		if ( ! $source instanceof \WP_Post || in_array( $source->post_status, [ 'trash', 'auto-draft' ], true ) ) {
			return new Outcome( 'no_source', 'source' );
		}

		$translations = GroupLinks::of( $source_id );

		if ( ( $translations[ $default_slug ] ?? 0 ) !== $source_id ) {
			return new Outcome( 'no_source', 'not_default' );
		}

		// Step 1: the translation.
		$translation_id = Scalar::int( $translations[ $target_slug ] ?? 0 );
		$translation    = $translation_id > 0 ? get_post( $translation_id ) : null;

		if ( ! $translation instanceof \WP_Post ) {
			return new Outcome( 'missing' );
		}

		if ( 'trash' === $translation->post_status ) {
			return new Outcome( 'target_gone', '', $translation_id );
		}

		if ( '' === $translation->post_content ) {
			return new Outcome( 'empty', '', $translation_id );
		}

		// Step 2, rights first: without them a user reaches neither the state nor the reconciler's put-back below.
		if ( ! Helper::user_can_edit_object( $translation_id ) || ! \PerfLocale\Util\ObjectPermission::read_text( $source ) ) {
			return new Outcome( 'skipped_permission', '', $translation_id );
		}

		// Step 0: an unfinished intent is reconciled under the target lock first.
		$resync_repo = new ResyncRepository();
		$narrow      = $resync_repo->state_hashes( $translation_id );

		// Nothing runs over an open crash record (a failed put-back stands too); a dry run reconciles nothing.
		if ( null !== $narrow && '' !== $narrow['intent_at'] && ( $o->dry_run || in_array( SyncRecorder::reconcile( $translation_id ), [ 'busy', 'failed' ], true ) ) ) {
			return new Outcome( 'busy', 'intent', $translation_id );
		}

		// Step 2, the rest: source status, scope.
		if ( 'publish' !== $source->post_status && ! $o->per_post ) {
			return new Outcome( 'no_source', 'unpublished', $translation_id );
		}

		$args = [
			'trigger'      => $o->trigger,
			'language_ids' => [ $language_id ],
			'user_id'      => get_current_user_id(),
		];

		if ( ! Scope::includes( $source, $args ) ) {
			return new Outcome( 'out_of_scope', '', $translation_id );
		}

		$protected     = 'publish' !== $source->post_status || '' !== $source->post_password;
		$fields_scoped = $o->fields && Scope::fields_included( $args );
		$source_hash   = Store::content_hash( $source->post_title, $source->post_content, $source->post_excerpt );
		$group_repo    = $plugin->group_repo();

		// Step 4: the recorded source state, before any parsing, field map or translation read (the cheap path).
		$every       = null;
		$field_drift = false;

		if ( null !== $narrow && $narrow['source_id'] === $source_id && $narrow['language_id'] === $language_id && $narrow['base_id'] > 0 && $narrow['source_hash'] === $source_hash ) {
			$base      = $fields_scoped ? $resync_repo->base_keys( $narrow['base_id'] ) : null;
			$base_keys = $fields_scoped ? ( null === $base ? null : $base['keys'] ) : [];
			$same      = null !== $base_keys && ( ! $fields_scoped || Store::digest_for_keys( $base_keys, PostTarget::field_values( $source_id, $base_keys ) ) === $narrow['fields_hash'] );

			// A field that became eligible with a value changed since the base was recorded is new work, never "unchanged".
			if ( $same && null !== $base ) {
				$added = FieldDrift::pair_added( $base['keys'], $base['seen'], $source, $translation_id, $every );

				// An unread source meta is no answer: never "unchanged", never a cleared flag.
				if ( null === $added ) {
					return new Outcome( 'busy', 'source_read', $translation_id );
				}

				$same = ! $added;
			}

			// Only the fields moved since the recorded state: a run that cannot apply them leaves the translation Outdated.
			$field_drift = ! $same && $fields_scoped && null !== $base_keys;

			if ( $same ) {
				if ( $o->per_post && ! $o->dry_run && 1 === $narrow['complete'] && SyncRecorder::QUALITY_REVISION !== $narrow['base_quality']
					&& TranslationStatus::NeedsUpdate->value === ( $group_repo->get_link_state( $translation_id, $language_id )['status'] ?? '' )
				) {
					self::clear_stale_flag( $translation_id, $language_id, $source_id, $source_hash, StateHash::legacy_post( $source->post_title, $source->post_content, $source->post_excerpt ), $fields_scoped ? (array) $base_keys : [], $narrow, $import_generation );
				}

				// A sync that left work for a person keeps the translation Outdated; the link is read only then.
				$outdated = 0 === $narrow['complete'] && in_array( $narrow['how'], self::HOW_WITH_COMPLETENESS, true )
					&& TranslationStatus::NeedsUpdate->value === ( $group_repo->get_link_state( $translation_id, $language_id )['status'] ?? '' );

				return new Outcome( $outdated ? 'outdated' : 'unchanged', 'recorded', $translation_id );
			}
		}

		$keys = $fields_scoped ? self::pair_keys( $source, $translation_id ) : [];

		// Keys the registry refuses here (ACF rows in another order) stay in the base with an unknown value: incomplete.
		$refused = $fields_scoped ? array_values( array_diff( $every ?? self::pair_keys( $source, $translation_id, false ), $keys ) ) : [];
		$keys    = array_merge( $keys, $refused );

		$read = PostTarget::read( $translation_id, $keys );

		if ( null === $read || 'trash' === $read['status'] ) {
			return new Outcome( 'target_gone', '', $translation_id );
		}

		$translation   = $read['post'];
		$source_values = PostTarget::field_values( $source_id, $keys );
		$source_fields = Store::digest_for_keys( $keys, $source_values );
		$link_status   = $group_repo->get_link_state( $translation_id, $language_id )['status'] ?? '';

		// Step 5: an identical proposal already held.
		$options_hash = $o->options_hash( self::hash_context( $o ) );
		$pending_repo = new PendingChangeRepository();
		$pending      = $pending_repo->pending_narrow_for_target( 'post', $translation_id, $language_id );

		if ( null !== $pending && 'post_locked' !== $pending['reason'] && ( self::same_proposal( $pending, $source_hash, $source_fields, $read, $options_hash ) || self::same_but_separators( $pending, $source, $source_fields, $read, $options_hash, $o->post_bound ) ) ) {
			return new Outcome( 'held_same', '', $translation_id, $pending['id'] );
		}

		// Step 6: the state and its base. An unusable state keeps its person marks; an oversized base is refused
		// as the engine refuses a pair that would not fit.
		$row       = null;
		$too_large = false;
		$valid     = SyncRecorder::valid_state( $translation_id, $source_id, $language_id, $translation, $row, $o->post_bound, $too_large );

		if ( $too_large ) {
			return new Outcome( 'unsupported', 'base: ' . Engine::ERROR_MEMORY, $translation_id );
		}

		$heuristic = false;
		$prev_base = 0;
		$block_map = [];
		$owned     = [];
		$unknown   = [];

		// Open work a write left stays open until a person changes the translation or approves a change.
		$newest      = false;
		$open_before = self::open_before( $row, $narrow, $read, $keys, $translation_id, $language_id, $newest );

		if ( null === $valid ) {
			$block_map = null === $row ? [] : PersonBlocks::carry( PendingChangeRepository::decode( $row['block_map'] ) );
			$carried   = $block_map;

			if ( TranslationStatus::NeedsUpdate->value !== $link_status ) {
				if ( $o->dry_run ) {
					return new Outcome( 'confirmed', '', $translation_id );
				}

				return self::recorded(
					$translation_id,
					$language_id,
					$read,
					$keys,
					$import_generation,
					static function ( \WP_Post $post ) use ( $source, $language_id, $translation_id, $carried ): Outcome {
						if ( SyncRecorder::confirm( $post, $source, $language_id, $carried ) <= 0 ) {
							return new Outcome( 'failed', 'state', $translation_id );
						}

						return new Outcome( 'confirmed', '', $translation_id );
					}
				);
			}

			$read_failed = false;
			$revision    = SyncRecorder::bootstrap_base( $translation, $source, $o->post_bound, $too_large, $read_failed );

			if ( $too_large ) {
				return new Outcome( 'unsupported', 'base: ' . Engine::ERROR_MEMORY, $translation_id );
			}

			if ( $read_failed ) {
				return new Outcome( 'busy', 'base_read', $translation_id );
			}

			if ( null === $revision ) {
				return new Outcome( 'no_base', SyncRecorder::no_base_reason( $translation, $source ), $translation_id );
			}

			if ( $revision['equals_live'] ) {
				// A bootstrapped state is incomplete: the translation stays Outdated.
				if ( $o->dry_run ) {
					return new Outcome( 'outdated', 'bootstrap', $translation_id );
				}

				return self::recorded(
					$translation_id,
					$language_id,
					$read,
					$keys,
					$import_generation,
					static function ( \WP_Post $post ) use ( $source, $language_id, $revision, $translation_id, $carried ): Outcome {
						if ( SyncRecorder::bootstrap( $post, $source, $language_id, $revision, $carried ) <= 0 ) {
							return new Outcome( 'failed', 'state', $translation_id );
						}

						return new Outcome( 'outdated', 'bootstrap', $translation_id );
					}
				);
			}

			$base_id = $o->dry_run ? 0 : SyncRecorder::revision_base( $source_id, $revision );

			if ( $base_id <= 0 && ! $o->dry_run ) {
				return new Outcome( 'failed', 'base', $translation_id );
			}

			$quality    = SyncRecorder::QUALITY_REVISION;
			$heuristic  = true;
			$base_known = false;
			$document   = [
				'title'   => $revision['title'],
				'content' => $revision['content'],
				'excerpt' => $revision['excerpt'],
				'keys'    => [],
				'values'  => [],
			];
		} else {
			$state      = $valid['state'];
			$document   = $valid['document'];
			$base_id    = $state['base_id'];
			$prev_base  = $state['base_id'];
			$quality    = $state['base_quality'];
			$heuristic  = SyncRecorder::QUALITY_REVISION === $quality;
			$base_known = '' !== $valid['base']['fields_hash'];
			$block_map  = PendingChangeRepository::decode( $state['block_map'] );
			$owned      = self::string_map( PendingChangeRepository::decode( $state['fields_owned'] ) );
			$unknown    = Scalar::strings( PendingChangeRepository::decode( $state['fields_unknown'] ) );

			if ( $read['hash'] !== $state['translation_hash'] ) {
				$rebased = SyncRecorder::rebase( $state, $document, $translation, $source, $o->dry_run );

				if ( 'revision' === $rebased['winner'] ) {
					$document   = $rebased['document'];
					$base_id    = $rebased['base_id'];
					$quality    = $rebased['quality'];
					$heuristic  = true;
					$base_known = false;
					$block_map  = PersonBlocks::carry( $block_map );
					$owned      = [];
					$unknown    = [];

					if ( TranslationStatus::NeedsUpdate->value !== $link_status && Store::content_hash( $document['title'], $document['content'], $document['excerpt'] ) === $source_hash ) {
						if ( $o->dry_run ) {
							return new Outcome( 'confirmed', 'rebase', $translation_id );
						}

						return self::recorded(
							$translation_id,
							$language_id,
							$read,
							$keys,
							$import_generation,
							static function ( \WP_Post $post ) use ( $source, $language_id, $translation_id, $block_map ): Outcome {
								if ( SyncRecorder::confirm( $post, $source, $language_id, $block_map ) <= 0 ) {
									return new Outcome( 'failed', 'state', $translation_id );
								}

								return new Outcome( 'confirmed', 'rebase', $translation_id );
							}
						);
					}
				}
			}
		}

		// Custom-field keys outside the base's list have an unknown base.
		$base_values = self::string_map( $document['values'] );

		if ( $fields_scoped && $base_known ) {
			$unknown = array_values( array_unique( array_merge( $unknown, array_diff( $keys, Scalar::strings( $document['keys'] ) ) ) ) );
		}

		$unknown = array_values( array_unique( array_merge( $unknown, $refused ) ) );
		$absent  = self::absent_keys( $source_id, $translation_id, $keys, $source_values, $read['fields'], $base_known ? $unknown : $keys, $refused );

		// A base key off the list whose value moved no proposal can show: a no-op must not settle it in a new base.
		$left_moved = $fields_scoped && FieldDrift::left_moved( Scalar::strings( $document['keys'] ), $base_values, $keys, $source_id );

		// Step 7: the engine.
		$engine_options = [
			'keep_exceptions' => $o->keep_exceptions,
			'threshold'       => $o->threshold,
			'block_map'       => $block_map,
			'post_type'       => $source->post_type,
			'segment'         => [
				'post_type'                      => $source->post_type,
				Segmenter::OPTION_PATTERN_MARKED => PatternMarks::resolver( $language_id ),
			],
			'fields_owned'    => $owned,
			'fields_unknown'  => $unknown,
			'fields_absent'   => $absent,
			'seed_cleared'    => ContentSync::seed_cleared_keys( $translation_id ),
			'base_known'      => $base_known,
		];

		$prepared = Engine::prepare(
			[
				'content' => $document['content'],
				'title'   => $document['title'],
				'excerpt' => $document['excerpt'],
				'fields'  => $base_values,
			],
			[
				'content' => $translation->post_content,
				'title'   => $translation->post_title,
				'excerpt' => $translation->post_excerpt,
				'fields'  => $read['fields'],
			],
			[
				'content' => $source->post_content,
				'title'   => $source->post_title,
				'excerpt' => $source->post_excerpt,
				'fields'  => $source_values,
			],
			$engine_options
		);

		if ( $prepared instanceof Proposal ) {
			return new Outcome( 'unsupported', $prepared->reason, $translation_id );
		}

		// Step 8: a proposal a person rejected or undone (query mode).
		if ( $o->query_mode ) {
			$decided = $pending_repo->newest_decided_narrow( 'post', $translation_id, $language_id );

			if ( null !== $decided && in_array( $decided['status'], [ PendingChangeRepository::STATUS_REJECTED, PendingChangeRepository::STATUS_REVERTED ], true ) && self::same_proposal( $decided, $source_hash, $source_fields, $read, $options_hash ) ) {
				return new Outcome( 'skipped_decided', '', $translation_id, $decided['id'] );
			}
		}

		// Step 9: machine translation of the units that need a text.
		$texts       = [];
		$field_texts = [];
		$verbatim    = [];
		$mt_chars    = 0;
		$mt_requests = 0;
		$rejected    = false;
		$dry_units   = 0;
		$lists       = [];

		if ( $o->machine ) {
			// A password-protected source the site does not send: nothing built, sent or written.
			if ( ! TranslationService::may_send_post( $source, 'resync' ) ) {
				return new Outcome( 'mt_unavailable', 'password_protected', $translation_id, 0, '', [], [], 0, 0, 'password_protected' );
			}

			$requests = [];

			foreach ( $prepared->units_needing_text() as $unit ) {
				$requests[] = [
					'index'  => $unit['index'],
					'text'   => $unit['text'],
					'format' => $unit['format'],
				];
			}

			// Least trust: a field of a post an author or last editor without unfiltered_html wrote is sent as such a
			// user may store it, so no machine output keeps its markup.
			require_once __DIR__ . '/../Translation/SourceTrust.php';
			$least = ! \PerfLocale\Translation\SourceTrust::trusted( (int) $source->ID );

			foreach ( $prepared->fields_needing_text() as $field ) {
				$format        = self::field_format( $field['key'], $source );
				$field['text'] = $least ? \PerfLocale\Translation\SourceTrust::mt_source( $field['text'] ) : $field['text'];

				// Nothing to translate (an SEO title of variables only), or a field that stores code: the source's value, never sent.
				if ( \PerfLocale\MachineTranslation\MetaTranslator::FORMAT_COPY === $format || UnitTranslator::verbatim( $field['text'], KeywordList::FORMAT === $format ? 'text' : $format ) ) {
					$verbatim[] = $field['key'];
					continue;
				}

				if ( KeywordList::FORMAT === $format ) {
					// One text request per keyword; rejoined after the call.
					$lists[ $field['key'] ] = 0;
					foreach ( KeywordList::split( $field['text'] ) as $n => $keyword ) {
						$requests[] = [
							'index'  => UnitTranslator::FIELD_PREFIX . $field['key'] . self::LIST_PIECE . $n,
							'text'   => $keyword,
							'format' => 'text',
						];
						++$lists[ $field['key'] ];
					}
					continue;
				}

				$requests[] = [
					'index'  => UnitTranslator::FIELD_PREFIX . $field['key'],
					'text'   => $field['text'],
					'format' => $format,
				];
			}

			if ( [] !== $requests ) {
				$service = new TranslationService( $plugin->settings(), $plugin->cache() );
				$mt_why  = self::mt_unavailable( $service );

				// Unavailable to the acting user: nothing written or recorded, so a later run that may use it translates.
				if ( '' !== $mt_why ) {
					return new Outcome( 'mt_unavailable', $mt_why, $translation_id, 0, '', [], [], 0, 0, $mt_why );
				}

				[ $requests, $billed ] = self::with_context( $prepared, $requests, $service, $source, $translation_id, $default_slug, $target_slug, $o );

				if ( $o->dry_run ) {
					$dry_units = count( $requests );
					$mt_chars  = UnitTranslator::chars( $requests, $billed, UnitTranslator::elements( $service->get_provider() ) );
				} else {
					$store_key = self::units_key( $translation_id );
					$version   = UnitTranslator::version( $service, $plugin->settings(), $default_slug, $target_slug, [ $read['hash'], $source_hash, $options_hash ] );
					$reuse     = UnitTranslator::kept( $store_key, $version, $requests );
					$chars     = UnitTranslator::chars( $reuse['pending'], $billed, UnitTranslator::elements( $service->get_provider() ) );
					$kept      = $reuse['kept'];

					if ( null !== $o->mt_budget && $chars > $o->mt_budget ) {
						return new Outcome( 'needs_estimate', '', $translation_id, 0, '', [], [], $chars );
					}

					if ( $service->would_exceed_limit( $chars ) ) {
						return new Outcome( 'limit', '', $translation_id, 0, '', [], [], $chars );
					}

					$outputs = $reuse['texts'];
					$refused = $reuse['rejected'];

					if ( [] !== $reuse['pending'] ) {
						try {
							$result = ( new UnitTranslator() )->translate( $service, $reuse['pending'], $default_slug, $target_slug, $o->before_request, UnitTranslator::checkpoint( $store_key, $version, $requests, $reuse, $kept ) );
						} catch ( \PerfLocale\Concurrency\BreakerOpenException $e ) {
							ChangeService::log( sprintf( 'machine translation of translation %d stopped: the provider is unavailable (%s)', $translation_id, $e->getMessage() ) );

							return new Outcome( 'failed', 'provider_unavailable', $translation_id );
						} catch ( \Throwable $e ) {
							ChangeService::log( sprintf( 'machine translation of translation %d failed: %s', $translation_id, $e->getMessage() ) );

							return new Outcome( 'failed', 'provider', $translation_id, 0, '', [], [], $chars );
						}

						$outputs     = $outputs + $result['texts'];
						$refused     = array_merge( $refused, $result['rejected'] );
						$mt_chars    = $result['chars'];
						$mt_requests = $result['requests'];

						// The job's clock stopped the pair: nothing written, kept outputs wait for the next attempt.
						// Unkept outputs would be sent again every slice, so the pair fails instead.
						if ( $result['stopped'] ) {
							if ( $result['unkept'] ) {
								ChangeService::log( sprintf( 'machine translation of translation %d stopped and its outputs could not be kept', $translation_id ) );

								return new Outcome( 'failed', 'not_kept', $translation_id, 0, '', [], [], $mt_chars, $mt_requests );
							}

							return new Outcome( 'deferred', '', $translation_id, 0, '', [], [], $mt_chars, $mt_requests );
						}
					}

					$rejected = [] !== $refused;

					foreach ( $outputs as $index => $text ) {
						if ( is_string( $index ) && str_starts_with( $index, UnitTranslator::FIELD_PREFIX ) ) {
							$field_texts[ substr( $index, strlen( UnitTranslator::FIELD_PREFIX ) ) ] = $text;
						} else {
							$texts[ (int) $index ] = $text;
						}
					}

					[ $field_texts, $failed_list ] = self::join_lists( $field_texts, $lists, $read['fields'] );
					$rejected                      = $rejected || $failed_list;
				}
			}
		}

		// Step 10: compose and verify. The person-edit check is read once here; a dry run reads nothing.
		$person_edited                          = ! $o->dry_run && ( false === $newest ? HumanEdit::since_last_automatic_write( $translation_id, $language_id, $read ) : HumanEdit::since( $newest, $read ) );
		[ $blocks_edited, $automatic, $unread ] = $person_edited ? self::person_basis( $translation_id, $block_map, $row, Scalar::str( $read['hash'] ) ) : [ false, null, false ];
		$proposal                               = Engine::complete( $prepared, $texts, $field_texts, $blocks_edited, $automatic, $verbatim );

		if ( Proposal::STATUS_PROPOSAL !== $proposal->status ) {
			return new Outcome( $proposal->status, $proposal->reason, $translation_id );
		}

		$flags = $proposal->flags;

		if ( $unread ) {
			$flags[] = Policy::FLAG_REVISIONS_UNREAD;
		}

		if ( $rejected ) {
			$flags[] = Policy::FLAG_QUALITY;
		}

		if ( $heuristic ) {
			$flags[] = 'heuristic_base';
		}

		if ( $protected ) {
			$flags[] = 'protected_source';
		}

		$noop = $proposal->content === $translation->post_content
			&& $proposal->title === $translation->post_title
			&& $proposal->excerpt === $translation->post_excerpt
			&& [] === $proposal->fields;

		if ( $o->dry_run ) {
			return self::dry_outcome( $translation_id, $proposal, $noop, $dry_units, $mt_chars, self::flag_list( $flags ), $field_drift || TranslationStatus::NeedsUpdate->value === $link_status, $open_before );
		}

		if ( $noop ) {
			$noop_flags = self::flag_list( $flags );

			return self::recorded(
				$translation_id,
				$language_id,
				$read,
				$keys,
				$import_generation,
				/**
				 * The no-op's state write on the read under the lock.
				 *
				 * @param \WP_Post             $post  The translation as read under the lock.
				 * @param array<string, mixed> $again The read under the lock.
				 * @phpstan-param LiveRead $again
				 */
				static function ( \WP_Post $post, array $again ) use ( $source, $language_id, $keys, $source_values, $source_hash, $proposal, $quality, $prev_base, $owned, $noop_flags, $link_status, $open_before, $field_drift, $left_moved ): Outcome {
					return self::record_noop( $post, $source, $language_id, $again, $keys, $source_values, $source_hash, $proposal, $quality, $prev_base, $owned, $noop_flags, TranslationStatus::NeedsUpdate->value === $link_status, $open_before, $field_drift || $left_moved ? $link_status : null, $left_moved );
				}
			);
		}

		// A field the acting user's HTML rules would alter is never written automatically.
		$fields = SaveProbe::fields_of(
			[
				'post_content' => $proposal->content,
				'post_title'   => $proposal->title !== $translation->post_title ? $proposal->title : null,
				'post_excerpt' => $proposal->excerpt !== $translation->post_excerpt ? $proposal->excerpt : null,
			],
			$translation->post_title,
			$translation->post_excerpt
		);
		$stored = SaveProbe::stored( $fields, $proposal->fields, $translation_id, (int) $source->ID );

		if ( [] !== $stored['held_fields'] || [] !== $stored['held_meta'] ) {
			$flags[] = 'kses';
		}

		// A composer fault that adds a script construct: held with that reason (Policy), and Apply or Approve refuse it.
		if ( ChangeService::adds_script( array_merge( [ $proposal->content, $proposal->title, $proposal->excerpt ], array_values( $proposal->fields ), array_values( $stored['fields'] ), array_values( $stored['meta'] ) ), $read, $source, $keys ) ) {
			$flags[] = 'unsafe_markup';
		}

		$flags = self::flag_list( $flags );

		// Step 11: decide, then apply or hold. The new base is stored first, so a held payload names a real row.
		$new_base_id = SyncRecorder::capture_base( $source, $source_values );

		if ( SyncRecorder::SOURCE_UNREAD === $new_base_id ) {
			return new Outcome( 'busy', 'source_read', $translation_id );
		}

		if ( $new_base_id <= 0 ) {
			return new Outcome( 'failed', 'base', $translation_id );
		}

		$edited = $person_edited || [] !== $proposal->edited_fields;
		$built  = ChangeBuilder::build(
			$proposal,
			[
				'translation_id' => $translation_id,
				'source_id'      => $source_id,
				'language_id'    => $language_id,
				'post_status'    => $translation->post_status,
				'live_title'     => $translation->post_title,
				'live_excerpt'   => $translation->post_excerpt,
				'live_content'   => $translation->post_content,
				'live_hash'      => $read['hash'],
				'live_digest'    => $read['digest'],
				'source_hash'    => $source_hash,
				'base_id'        => $new_base_id,
				'base_quality'   => $quality,
				'base_fields'    => $read['digest'],
				'source_fields'  => $source_fields,
				'keys'           => $keys,
				'options_hash'   => $options_hash,
				'trigger'        => $o->trigger,
				'job_id'         => $o->job_id,
				'user_id'        => get_current_user_id(),
				'actor'          => Policy::current_actor(),
				'flags'          => $flags,
				'parts'          => $prepared->parts( $texts, $field_texts, $proposal->person_units ),
				'mt_chars'       => $mt_chars,
				'open_before'    => $open_before,
			]
		);

		$change  = $built['change'];
		$payload = $built['payload'];
		$facts   = Policy::facts_of( $built['summary'], $flags );

		$facts['edited_since'] = $edited;
		$facts['person_block'] = $proposal->person_changed > 0 && self::protects_person_blocks( $plugin->settings(), $translation_id, $source_id, $language_id );

		$decided            = Policy::decide( $change, [ 'trigger' => $o->trigger ], $facts );
		$change['decision'] = $decided['decision'];
		$change['reason']   = $decided['reason'];
		$change['payload']  = $payload;
		$counts             = self::int_map( $built['summary'] );

		if ( Policy::REVIEW === $decided['decision'] ) {
			return self::hold( $change, $translation_id, $decided['reason'], $counts, $flags, $mt_chars, $mt_requests, $import_generation );
		}

		$applied = ChangeService::apply(
			$change,
			$payload,
			[
				'ignore_own_lock'   => $o->ignore_own_lock,
				'import_generation' => $import_generation,
			]
		);

		switch ( $applied['status'] ) {
			case 'applied':
				return new Outcome( 'applied', '', $translation_id, $applied['id'], $decided['decision'], $counts, $flags, $mt_chars, $mt_requests );

			case 'edited':
			case 'post_locked':
			case 'missed_schedule':
			case 'kses':
			case 'unsafe_markup':
				$change['decision'] = Policy::REVIEW;
				$change['reason']   = $applied['reason'];

				return self::hold( $change, $translation_id, $applied['reason'], $counts, $flags, $mt_chars, $mt_requests, $import_generation );

			case 'forbidden':
				return new Outcome( 'forbidden', '', $translation_id );

			case 'invalid':
				return new Outcome( 'failed', $applied['reason'], $translation_id, 0, $decided['decision'], $counts, $flags, $mt_chars, $mt_requests );

			default:
				return new Outcome( $applied['status'], $applied['reason'], $translation_id, 0, $decided['decision'], $counts, $flags, $mt_chars, $mt_requests );
		}
	}

	/**
	 * How a person-edited translation's blocks are judged: whether a differing block is a person's, the parts the
	 * last automatic write left (from its revision; null: every block is a person's), and whether the walk's
	 * budget stopped early (so null may hide an unread revision).
	 *
	 * @param int                            $translation_id The translation.
	 * @param array<mixed>                   $block_map      The block map the run uses.
	 * @param array<string, int|string>|null $row            The state row as stored; null when there is none.
	 * @param string                         $live_hash      The live translation's hash.
	 * @return array{bool, array<string, true>|null, bool}
	 */
	private static function person_basis( int $translation_id, array $block_map, ?array $row, string $live_hash ): array {
		$recorded = Scalar::str( $row['translation_hash'] ?? '' );
		$basis    = PersonBlocks::basis( $block_map, Scalar::str( $row['how'] ?? '' ), $recorded, $live_hash );

		if ( PersonBlocks::BASIS_LIVE === $basis ) {
			return [ false, null, false ];
		}

		if ( PersonBlocks::BASIS_REVISION !== $basis ) {
			return [ true, null, false ];
		}

		[ $content, $unread ] = self::automatic_revision( $translation_id, $recorded, microtime( true ) + self::BASIS_SECONDS, self::BASIS_REVISIONS );

		return [ true, null === $content ? null : PersonBlocks::owns( $content ), $unread ];
	}

	/**
	 * The content of the translation's revision with the recorded hash, walked newest first in size-bounded reads.
	 * No index gives that order, so ids are sorted without reading text; no revision enters the object cache.
	 *
	 * @param int    $translation_id The translation.
	 * @param string $recorded       The hash the last automatic write recorded.
	 * @param float  $deadline       Unix time with microseconds after which no further read starts.
	 * @param int    $max            The most revisions read.
	 * @return array{0: string|null, 1: bool} The matching content (null: none read), and whether the budget stopped the walk early.
	 */
	private static function automatic_revision( int $translation_id, string $recorded, float $deadline, int $max ): array {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb || $max < 1 ) {
			return [ null, false ];
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The posts table is bound with %i and each id list is built of %d placeholders; a revision read here must not enter the object cache.
		$ids   = array_map(
			[ Scalar::class, 'int' ],
			(array) $wpdb->get_col(
				$wpdb->prepare(
					'SELECT ID FROM %i USE INDEX FOR ORDER BY () WHERE post_parent = %d AND post_type = %s AND post_status = %s ORDER BY ID DESC LIMIT %d',
					$wpdb->posts,
					$translation_id,
					'revision',
					'inherit',
					$max + 1
				)
			)
		);
		$older = count( $ids ) > $max;
		$read  = 0;

		foreach ( array_chunk( array_slice( $ids, 0, $max ), self::BASIS_PAGE ) as $chunk ) {
			if ( $read > 0 && microtime( true ) >= $deadline ) {
				return [ null, true ];
			}

			$sizes = [];

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- bytes_sql() is one of two fixed strings.
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT p.ID, ' . PostReads::bytes_sql() . ' AS bytes FROM %i p WHERE p.ID IN (' . implode( ', ', array_fill( 0, count( $chunk ), '%d' ) ) . ')', array_merge( [ $wpdb->posts ], $chunk ) ), ARRAY_A ) as $row ) {
				$sizes[ Scalar::int( $row['ID'] ?? 0 ) ] = Scalar::int( $row['bytes'] ?? 0 );
			}

			$batches = [];
			$batch   = [];
			$bytes   = 0;

			foreach ( $chunk as $id ) {
				$size = $sizes[ $id ] ?? 0;

				if ( [] !== $batch && $bytes + $size > self::BASIS_BATCH_BYTES ) {
					$batches[] = $batch;
					$batch     = [];
					$bytes     = 0;
				}

				$batch[] = $id;
				$bytes  += $size;
			}

			$batches[] = $batch;

			foreach ( $batches as $batch ) {
				if ( $read > 0 && microtime( true ) >= $deadline ) {
					return [ null, true ];
				}

				$read     += count( $batch );
				$revisions = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT ID, post_title, post_content, post_excerpt FROM %i WHERE ID IN (' . implode( ', ', array_fill( 0, count( $batch ), '%d' ) ) . ') ORDER BY ID DESC',
						array_merge( [ $wpdb->posts ], $batch )
					),
					ARRAY_A
				);

				foreach ( is_array( $revisions ) ? $revisions : [] as $revision ) {
					$content = Scalar::str( $revision['post_content'] ?? '' );

					if ( Store::content_hash( Scalar::str( $revision['post_title'] ?? '' ), $content, Scalar::str( $revision['post_excerpt'] ?? '' ) ) === $recorded ) {
						return [ $content, false ];
					}
				}

				unset( $revisions, $revision, $content );
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return [ null, $older ];
	}

	/**
	 * Whether an update that changes a block a person wrote is held for review: the setting, then the filter.
	 *
	 * @param Settings $settings       The settings.
	 * @param int      $translation_id The translation.
	 * @param int      $source_id      The source.
	 * @param int      $language_id    The translation's language.
	 * @return bool
	 */
	public static function protects_person_blocks( Settings $settings, int $translation_id, int $source_id, int $language_id ): bool {
		$protect = ResyncSettings::PERSON_BLOCKS_ALWAYS === ResyncSettings::person_blocks( $settings );

		/**
		 * Filters whether a Content ReSync update that changes a block written or changed outside ReSync is held for review.
		 * Only tightens: true holds it; false or a non-bool keeps the setting's answer.
		 *
		 * @internal
		 * @hook  perflocale/resync/protect_person_blocks
		 * @since 1.0.8
		 *
		 * @param bool $protect        Whether the update is held for review.
		 * @param int  $translation_id The translation.
		 * @param int  $source_id      The source.
		 * @param int  $language_id    The translation's language.
		 */
		$filtered = apply_filters( 'perflocale/resync/protect_person_blocks', $protect, $translation_id, $source_id, $language_id );

		return $protect || true === $filtered;
	}

	/**
	 * Whether the translation still holds open work a write left: incomplete state, unchanged since, and the last
	 * change left work (left_work()). A failed read proves nothing, so it counts as open.
	 *
	 * @param array<string, int|string>|null       $row            The state as stored, valid or not; null when there is none.
	 * @phpstan-param StateRow|null $row
	 * @param array<string, int|string>|null       $narrow         The step 0 read of the state.
	 * @phpstan-param StateRow|null $narrow
	 * @param array<string, mixed>                 $read           The translation's read.
	 * @phpstan-param LiveRead $read
	 * @param array<int, string>                   $keys           The pair's keys.
	 * @param int                                  $translation_id Translation id.
	 * @param int                                  $language_id    Language id.
	 * @param array<string, int|string>|null|false $newest         Receives the newest applied row (null: none); false when not read.
	 * @param-out array{id: int, reason: string, result_hash: string, summary: string}|null|false $newest
	 * @return bool
	 */
	private static function open_before( ?array $row, ?array $narrow, array $read, array $keys, int $translation_id, int $language_id, array|null|false &$newest ): bool {
		if ( null === $row ) {
			return null !== $narrow;
		}

		if ( 0 !== $row['complete'] || ! in_array( $row['how'], self::HOW_WITH_COMPLETENESS, true ) ) {
			return false;
		}

		// A legacy-formula translation hash proves no edit: the open work stays open.
		if ( StateHash::is_legacy( $row['translation_hash'] ) ) {
			return true;
		}

		if ( $read['hash'] !== $row['translation_hash'] || ( [] !== $keys && $read['digest'] !== $row['translation_fields_hash'] ) ) {
			return false;
		}

		global $wpdb;

		$newest = ( new PendingChangeRepository() )->newest_applied_narrow( 'post', $translation_id, $language_id );

		if ( null === $newest ) {
			return $wpdb instanceof \wpdb && '' !== $wpdb->last_error;
		}

		return self::left_work( $newest );
	}

	/**
	 * Whether an automatic ReSync write left work for a person (source-language part, rejected output, carried
	 * work). A person's approval resolves it: the flag stays, the next run may clear it.
	 *
	 * @param array<string, int|string> $written The change row, narrow (newest_applied_narrow()).
	 * @phpstan-param array{id: int, reason: string, result_hash: string, summary: string} $written
	 * @return bool
	 */
	private static function left_work( array $written ): bool {
		$summary = PendingChangeRepository::decode( $written['summary'] );

		if ( 1 !== Scalar::int( $summary['resync'] ?? 0 ) || 'approved' === $written['reason'] ) {
			return false;
		}

		return Scalar::int( $summary['left_source'] ?? 0 ) > 0
			|| in_array( Policy::FLAG_QUALITY, Scalar::strings( $summary['flags'] ?? [] ), true )
			|| true === ( $summary['open_before'] ?? false );
	}

	/**
	 * A dry run's outcome at the composition: `unchanged` for a no-op, else `proposal` with counts and characters.
	 *
	 * @param int                $translation_id Translation id.
	 * @param Proposal           $proposal       The proposal composed without machine texts.
	 * @param bool               $noop           Whether it equals the translation.
	 * @param int                $units          Units and fields it would send (0 outside machine mode).
	 * @param int                $chars          Characters it would send.
	 * @param array<int, string> $flags          Flags.
	 * @param bool               $flagged        Whether the translation is Outdated now.
	 * @param bool               $open_before    Whether the state records open work the translation still holds.
	 * @phpstan-param list<string> $flags
	 * @return Outcome
	 */
	private static function dry_outcome( int $translation_id, Proposal $proposal, bool $noop, int $units, int $chars, array $flags, bool $flagged, bool $open_before ): Outcome {
		$counts = self::int_map( $proposal->counts );

		if ( $noop ) {
			return new Outcome( $flagged && ! ( $proposal->complete && [] === Policy::counted_flags( $flags ) && ! $open_before ) ? 'outdated' : 'unchanged', 'no_op', $translation_id, 0, '', $counts, $flags );
		}

		$counts['machine_translated'] = $units;
		$counts['left_source']        = max( 0, ( $counts['left_source'] ?? 0 ) - $units );
		$counts['mt_units']           = $units;

		return new Outcome( 'proposal', '', $translation_id, 0, '', $counts, $flags, $chars );
	}

	/**
	 * The transient key of a pair's kept machine outputs (per blog and translation).
	 *
	 * @param int $translation_id Translation id.
	 * @return string
	 */
	public static function units_key( int $translation_id ): string {
		return 'perflocale_resync_units_' . md5( get_current_blog_id() . ':' . $translation_id );
	}

	/**
	 * A state-only write under the target lock after a second read: a write since the run's read makes it `stale`,
	 * so no state claims the current source for text the run never saw.
	 *
	 * @param int                  $translation_id Translation id.
	 * @param int                  $language_id    Language id.
	 * @param array<string, mixed> $read           The run's read.
	 * @phpstan-param LiveRead $read
	 * @param array<int, string>   $keys           The pair's keys.
	 * @param int                  $import_generation The pair's import_fence(): an import since refuses the write.
	 * @param callable             $record         The write, given the translation and the second read; returns the outcome.
	 * @phpstan-param callable(\WP_Post, LiveRead): Outcome $record
	 * @return Outcome
	 */
	private static function recorded( int $translation_id, int $language_id, array $read, array $keys, int $import_generation, callable $record ): Outcome {
		$outcome = Lock::with(
			ChangeService::lock_name( 'post', $translation_id, $language_id ),
			ChangeService::target_lock_ttl(),
			static function () use ( $translation_id, $read, $keys, $import_generation, $record ): Outcome {
				if ( ChangeService::import_moved( $import_generation ) ) {
					return new Outcome( 'busy', 'import', $translation_id );
				}

				$again = PostTarget::read( $translation_id, $keys );

				if ( null === $again || 'trash' === $again['status'] ) {
					return new Outcome( 'target_gone', '', $translation_id );
				}

				if ( $again['hash'] !== $read['hash'] || $again['digest'] !== $read['digest'] ) {
					return new Outcome( 'stale', '', $translation_id );
				}

				return $record( $again['post'], $again );
			}
		);

		return $outcome instanceof Outcome ? $outcome : new Outcome( 'busy', 'state', $translation_id );
	}

	/**
	 * Keys at stake that both posts have no row for, from meta loads checked for errors (null alone may be
	 * several rows or a failed read). Reads only when such a key exists.
	 *
	 * @param int                        $source_id          Source id.
	 * @param int                        $translation_id     Translation id.
	 * @param list<string>               $keys               The pair's keys.
	 * @param array<string, string|null> $source_values      The source's values.
	 * @param array<string, string|null> $translation_values The translation's values.
	 * @param list<string>               $at_stake           Keys whose base is unknown.
	 * @param list<string>               $refused            Keys the registry refuses here (always unknown).
	 * @return list<string>
	 */
	private static function absent_keys( int $source_id, int $translation_id, array $keys, array $source_values, array $translation_values, array $at_stake, array $refused ): array {
		$candidates = [];

		foreach ( $keys as $key ) {
			if ( null === ( $source_values[ $key ] ?? null ) && null === ( $translation_values[ $key ] ?? null ) && in_array( $key, $at_stake, true ) && ! in_array( $key, $refused, true ) ) {
				$candidates[] = $key;
			}
		}

		if ( [] === $candidates ) {
			return [];
		}

		$source      = PostTarget::rowless_keys( $source_id, $candidates );
		$translation = false === $source ? false : PostTarget::rowless_keys( $translation_id, $candidates );

		return false === $source || false === $translation ? [] : array_values( array_intersect( $source, $translation ) );
	}

	/**
	 * The pair's field keys: writable, storable by the field layer, and held as one unit (FieldDrift, as the scan).
	 *
	 * @param \WP_Post $source         Source.
	 * @param int      $translation_id Translation id.
	 * @param bool     $aligned        Ask the registry for this translation (false: the source's keys, rows it refuses included).
	 * @return list<string>
	 */
	private static function pair_keys( \WP_Post $source, int $translation_id, bool $aligned = true ): array {
		return FieldDrift::pair_keys( $source, $translation_id, $aligned ? FieldDrift::source_keys( $source, $translation_id ) : null );
	}

	/**
	 * Clear a stale Outdated flag on a per-post run when the translation still matches its recorded state.
	 *
	 * @param int                       $translation_id Translation id.
	 * @param int                       $language_id    Language id.
	 * @param int                       $source_id      Source id.
	 * @param string                    $source_hash    The source's hash.
	 * @param string                    $legacy_hash    The legacy-formula hash of the same source state.
	 * @param array<int, string>        $base_keys      The base's keys while fields are in scope, else [].
	 * @param array<string, int|string> $narrow         The state's hash columns.
	 * @phpstan-param StateRow $narrow
	 * @param int                       $import_generation The pair's import_fence(): the flag may be the file's.
	 * @return void
	 */
	private static function clear_stale_flag( int $translation_id, int $language_id, int $source_id, string $source_hash, string $legacy_hash, array $base_keys, array $narrow, int $import_generation ): void {
		$read = PostTarget::read( $translation_id, $base_keys );

		if ( null === $read || 'trash' === $read['status'] || $read['hash'] !== $narrow['translation_hash'] || ( [] !== $base_keys && $read['digest'] !== $narrow['translation_fields_hash'] ) ) {
			return;
		}

		if ( SyncRecorder::stale_mark_keeps_flag( $translation_id, $language_id ) || ChangeService::import_moved( $import_generation ) ) {
			return;
		}

		$new = 'publish' === $read['status'] ? TranslationStatus::Published->value : TranslationStatus::Empty->value;

		( new \PerfLocale\Database\Repository\TranslationGroupWrites( \PerfLocale\Plugin::get_instance()->cache() ) )->clear_needs_update_if_source( $translation_id, $language_id, $new, $source_id, $source_hash, $legacy_hash );
	}

	/**
	 * Hold a change for review.
	 *
	 * @param array<string, mixed> $change         The change row with its payload.
	 * @param int                  $translation_id Translation id.
	 * @param string               $reason         The reason.
	 * @param array<string, int>   $counts         Counts.
	 * @param array<int, string>   $flags          Flags.
	 * @param int                  $mt_chars       Characters sent.
	 * @param int                  $mt_requests    Requests made.
	 * @phpstan-param list<string> $flags
	 * @param int                  $import_generation The pair's import_fence().
	 * @return Outcome
	 */
	private static function hold( array $change, int $translation_id, string $reason, array $counts, array $flags, int $mt_chars, int $mt_requests, int $import_generation ): Outcome {
		$held = ChangeService::hold( $change, [ 'import_generation' => $import_generation ] );

		if ( 'held' === $held['status'] ) {
			return new Outcome( 'held', $reason, $translation_id, $held['id'], Policy::REVIEW, $counts, $flags, $mt_chars, $mt_requests );
		}

		return new Outcome( $held['status'], $held['reason'] ?? $reason, $translation_id, 0, Policy::REVIEW, $counts, $flags, $mt_chars, $mt_requests );
	}

	/**
	 * Record a proven no-op: the new source becomes the base and nothing is written to the post. It finishes no
	 * intent: one another writer began after step 0 stays its crash record.
	 *
	 * @param \WP_Post                   $translation   Translation, as read under the lock.
	 * @param \WP_Post                   $source        Source.
	 * @param int                        $language_id   Language.
	 * @param array<string, mixed>       $read          The read under the lock.
	 * @phpstan-param LiveRead $read
	 * @param array<int, string>         $keys          The pair's keys.
	 * @param array<string, string|null> $source_values The source's values.
	 * @param string                     $source_hash   The source's hash.
	 * @param Proposal                   $proposal      The proposal.
	 * @param string                     $quality       The base quality the run used.
	 * @param int                        $prev_base     The base the run used (0 for a bootstrap).
	 * @param array<string, string>      $owned         The owned hashes, re-keyed.
	 * @param array<int, string>         $flags         The flags.
	 * @param bool                       $flagged       Whether the translation was Outdated when the run read it.
	 * @param bool                       $open_before   Whether the state records open work the translation still holds.
	 * @param string|null                $drift_status  The link status the run read, when only the source's fields moved; else null.
	 * @param bool                       $left_moved    A base key off the list has another value now (FieldDrift::left_moved()).
	 * @phpstan-param list<string> $flags
	 * @return Outcome `unchanged`, or `outdated` when the translation was Outdated and the flag is not settled.
	 */
	private static function record_noop( \WP_Post $translation, \WP_Post $source, int $language_id, array $read, array $keys, array $source_values, string $source_hash, Proposal $proposal, string $quality, int $prev_base, array $owned, array $flags, bool $flagged, bool $open_before, ?string $drift_status = null, bool $left_moved = false): Outcome {
		$translation_id = $translation->ID;

		// An incomplete no-op keeps the previous state and base, so other options can still send the delta.
		if ( ( ! $proposal->complete || $left_moved ) && $prev_base > 0 ) {
			// A field change nothing applied (a new ACF row, a new field) is work for a person: Outdated, as a text change is.
			if ( ! $flagged && null !== $drift_status && '' !== $drift_status ) {
				$flagged = \PerfLocale\Plugin::get_instance()->group_repo()->update_link_status( $translation_id, $language_id, TranslationStatus::NeedsUpdate->value, '', \PerfLocale\Enum\ObjectType::Post, $drift_status );
			}

			return new Outcome( $flagged ? 'outdated' : 'unchanged', 'no_op', $translation_id, 0, '', self::int_map( $proposal->counts ), $flags );
		}

		$base_id = SyncRecorder::capture_base( $source, $source_values );

		if ( SyncRecorder::SOURCE_UNREAD === $base_id ) {
			return new Outcome( 'busy', 'source_read', $translation_id );
		}

		if ( $base_id <= 0 ) {
			return new Outcome( 'failed', 'base', $translation_id );
		}

		$kept = [];

		foreach ( $owned as $key => $hash ) {
			if ( is_string( $read['fields'][ $key ] ?? null ) && hash( 'sha256', $read['fields'][ $key ] ) === $hash ) {
				$kept[ $key ] = $hash;
			}
		}

		$complete = $proposal->complete && [] === Policy::counted_flags( $flags ) && ! $open_before;

		$recorded = SyncRecorder::after_write(
			$translation_id,
			$source->ID,
			$language_id,
			$base_id,
			[
				'how'                     => SyncRecorder::HOW_UNCHANGED,
				'base_quality'            => $quality,
				'prev_base_id'            => $prev_base,
				'source_hash'             => $source_hash,
				'fields_hash'             => Store::fields_digest( $source_values ),
				'translation_hash'        => $read['hash'],
				'translation_fields_hash' => Store::digest_for_keys( $keys, $read['fields'] ),
				'complete'                => $complete ? 1 : 0,
				'fields_owned'            => $kept,
				'fields_unknown'          => ChangeBuilder::unknown_keys( $proposal ),
				'block_map'               => $proposal->block_map,
			],
			false
		);

		// No flag is settled on a state that was never recorded.
		if ( $recorded <= 0 ) {
			return new Outcome( 'failed', 'state', $translation_id );
		}

		$settled = $complete && SyncRecorder::QUALITY_REVISION !== $quality;

		if ( $settled ) {
			$base = ( new ResyncRepository() )->base( $base_id );

			if ( null !== $base ) {
				SyncRecorder::settle_flag( $translation_id, $language_id, $source->ID, $base, true, $quality, $translation->post_status );
			}
		}

		return new Outcome( $flagged && ! $settled ? 'outdated' : 'unchanged', 'no_op', $translation_id, 0, '', self::int_map( $proposal->counts ), $flags );
	}

	/**
	 * Whether a stored row is the proposal this run would make: same source and translation state, same options.
	 * Field digests count too, so a field-only change is proposed and a row the write path would refuse is superseded.
	 *
	 * @param array{base_hash: string, summary: string} $row           The narrow row.
	 * @param string                                    $source_hash   The source's hash now.
	 * @param string                                    $source_fields The source's field digest over the pair's keys now.
	 * @param array<string, mixed>                      $read          The translation's live read.
	 * @phpstan-param LiveRead $read
	 * @param string                                    $options_hash  The run's options hash.
	 * @return bool
	 */
	private static function same_proposal( array $row, string $source_hash, string $source_fields, array $read, string $options_hash ): bool {
		$summary = PendingChangeRepository::decode( $row['summary'] );

		return Scalar::str( $summary['source_hash'] ?? '' ) === $source_hash
			&& Scalar::str( $summary['fields_hash'] ?? '' ) === $source_fields
			&& $row['base_hash'] === $read['hash']
			&& Scalar::str( $summary['base_fields_hash'] ?? '' ) === $read['digest']
			&& Scalar::str( $summary['options_hash'] ?? '' ) === $options_hash;
	}

	/**
	 * Whether a pending row still stands for a source saved since: its base and the live source differ only in
	 * whitespace between blocks, all else as same_proposal(). The base is read only when the rest matches.
	 *
	 * @param array{base_hash: string, summary: string} $row           The pending row, narrow.
	 * @param \WP_Post                                  $source        The source.
	 * @param string                                    $source_fields The source's field digest over the pair's keys now.
	 * @param array<string, mixed>                      $read          The translation's live read.
	 * @phpstan-param LiveRead $read
	 * @param string                                    $options_hash  The run's options hash.
	 * @param int                                       $bound         Most text bytes the base's document may hold.
	 * @return bool
	 */
	private static function same_but_separators( array $row, \WP_Post $source, string $source_fields, array $read, string $options_hash, int $bound ): bool {
		$summary = PendingChangeRepository::decode( $row['summary'] );

		if ( Scalar::str( $summary['fields_hash'] ?? '' ) !== $source_fields
			|| $row['base_hash'] !== $read['hash']
			|| Scalar::str( $summary['base_fields_hash'] ?? '' ) !== $read['digest']
			|| Scalar::str( $summary['options_hash'] ?? '' ) !== $options_hash
		) {
			return false;
		}

		$base_id = Scalar::int( $summary['base_id'] ?? 0 );
		$repo    = new ResyncRepository();

		if ( PHP_INT_MAX !== $bound && $base_id > 0 && PostReads::too_large( [ $base_id => $repo->base_bytes( $base_id ) ], $base_id, $bound ) ) {
			return false;
		}

		$base     = $base_id > 0 ? $repo->base( $base_id ) : null;
		$document = null === $base ? null : SyncRecorder::base_document( $base );

		if ( null === $document || $document['title'] !== $source->post_title || $document['excerpt'] !== $source->post_excerpt ) {
			return false;
		}

		return Composer::whitespace_only( BlockScanner::scan( $document['content'] ), BlockScanner::scan( $source->post_content ) );
	}

	/**
	 * The requests with their context: the configured level, else section only, else none, so the pair stays
	 * within the request cap. The level is chosen before `perflocale/mt/context` runs (once per unit).
	 *
	 * @param Prepared                         $prepared       The prepared pair.
	 * @param array<int, array<string, mixed>> $requests       The requests.
	 * @param TranslationService               $service        The service.
	 * @param \WP_Post                         $source         The source.
	 * @param int                              $translation_id The translation.
	 * @param string                           $source_lang    Source language slug.
	 * @param string                           $target_lang    Target language slug.
	 * @param RunOptions                       $o              The run's options.
	 * @phpstan-param list<array{index: int|string, text: string, format: string}> $requests
	 * @return array{0: list<array{index: int|string, text: string, format: string, context?: string}>, 1: bool} The requests and whether the provider bills their context.
	 */
	private static function with_context( Prepared $prepared, array $requests, TranslationService $service, \WP_Post $source, int $translation_id, string $source_lang, string $target_lang, RunOptions $o ): array {
		$settings = \PerfLocale\Plugin::get_instance()->settings();
		$mode     = ContextSettings::mode( $settings );

		if ( ContextBuilder::MODE_OFF === $mode ) {
			return [ $requests, false ];
		}

		$provider = $service->get_provider();

		if ( ! $service->provider_supports_context( $provider ) ) {
			return [ $requests, false ];
		}

		$provider_id = $provider->get_id();
		$size        = UnitTranslator::batch_size( $provider_id );

		/**
		 * Filters how many provider requests the context of one pair may need; over it, less context is sent.
		 *
		 * @internal Not public: the context setting is the cost control.
		 * @hook perflocale/resync/max_requests_per_pair
		 * @since 1.0.8
		 *
		 * @param int    $requests    Default 10.
		 * @param string $provider_id The provider.
		 */
		$cap   = max( 1, Scalar::int( apply_filters( 'perflocale/resync/max_requests_per_pair', 10, $provider_id ) ) );
		$limit = max( $cap, UnitTranslator::request_count( $requests, $size ) );

		$configured = self::context_settings();
		$levels     = [ $configured ];

		if ( ContextBuilder::MODE_SECTION !== $mode || $configured['previous'] ) {
			$levels[] = [
				'mode'     => ContextBuilder::MODE_SECTION,
				'previous' => false,
			] + $configured;
		}

		$chosen = null;

		foreach ( $levels as $level ) {
			if ( UnitTranslator::request_count( self::attach_contexts( $requests, ContextBuilder::build( $prepared, $requests, $level, null ) ), $size ) <= $limit ) {
				$chosen = $level;
				break;
			}
		}

		if ( null === $chosen ) {
			return [ $requests, false ];
		}

		$args = [
			'object_type'               => self::object_type( $source->post_type ),
			'object_id'                 => $translation_id,
			'source_object_id'          => $source->ID,
			'post_type'                 => $source->post_type,
			'source_language'           => $source_lang,
			'target_language'           => $target_lang,
			'trigger'                   => $o->trigger,
			'provider'                  => $provider_id,
			'provider_supports_context' => true,
			'job_id'                    => $o->job_id,
			'user_id'                   => get_current_user_id(),
		];

		$with = self::attach_contexts( $requests, ContextBuilder::build( $prepared, $requests, $chosen, $args ) );

		if ( UnitTranslator::request_count( $with, $size ) > $limit ) {
			return [ $requests, false ];
		}

		return [ $with, $service->context_billed( $provider ) ];
	}

	/**
	 * The context settings that shape a proposal's hash; [] when no context would be sent.
	 *
	 * @param RunOptions $o The run's options.
	 * @return array<string, mixed>
	 */
	private static function hash_context( RunOptions $o ): array {
		if ( ! $o->machine ) {
			return [];
		}

		$context = self::context_settings();

		if ( ContextBuilder::MODE_OFF === $context['mode'] ) {
			return [];
		}

		$plugin  = \PerfLocale\Plugin::get_instance();
		$service = new TranslationService( $plugin->settings(), $plugin->cache() );

		try {
			return $service->provider_supports_context( $service->get_provider() ) ? $context : [];
		} catch ( \RuntimeException $e ) {
			return [];
		}
	}

	/**
	 * The machine-translation context settings.
	 *
	 * @return array{mode: string, neighbours: int, previous: bool, max_chars: int}
	 */
	private static function context_settings(): array {
		$settings = \PerfLocale\Plugin::get_instance()->settings();

		return [
			'mode'       => ContextSettings::mode( $settings ),
			'neighbours' => ContextSettings::neighbours( $settings ),
			'previous'   => ContextSettings::previous( $settings ),
			'max_chars'  => ContextSettings::max_chars( $settings ),
		];
	}

	/**
	 * The requests with their contexts.
	 *
	 * @param array<int, array<string, mixed>> $requests The requests.
	 * @param array<int, string>               $contexts Context per request.
	 * @phpstan-param list<array{index: int|string, text: string, format: string}> $requests
	 * @return list<array{index: int|string, text: string, format: string, context?: string}>
	 */
	private static function attach_contexts( array $requests, array $contexts ): array {
		foreach ( $requests as $i => $request ) {
			if ( '' !== ( $contexts[ $i ] ?? '' ) ) {
				$requests[ $i ]['context'] = $contexts[ $i ];
			}
		}

		return $requests;
	}

	/**
	 * The object type the context filter names for a post type.
	 *
	 * @param string $post_type Post type.
	 * @return string
	 */
	private static function object_type( string $post_type ): string {
		return match ( $post_type ) {
			'wp_template'      => 'template',
			'wp_template_part' => 'template_part',
			'wp_block'         => 'pattern',
			'wp_navigation'    => 'navigation',
			default            => 'post',
		};
	}

	/**
	 * Why machine mode is unavailable now (`mt_disabled`, `no_capability`, `no_provider`); '' when available.
	 *
	 * @param TranslationService $service The service.
	 * @return string
	 */
	public static function mt_unavailable( TranslationService $service ): string {
		if ( ! \PerfLocale\Plugin::get_instance()->settings()->mt_enabled() ) {
			return 'mt_disabled';
		}

		if ( ! current_user_can( 'perflocale_use_mt' ) ) {
			return 'no_capability';
		}

		try {
			$service->get_provider();
		} catch ( \RuntimeException $e ) {
			return 'no_provider';
		}

		return '';
	}

	/**
	 * Rejoin keyword-list pieces into their field's text. A list with an unusable or missing piece takes the
	 * translation's own value whole (none when it has none) and flags the pair's quality.
	 *
	 * @param array<string, string> $field_texts Machine outputs per field key, pieces included.
	 * @param array<string, int>    $lists       Pieces sent per keyword-list key.
	 * @param array<string, mixed>  $current     The translation's field values as read.
	 * @return array{0: array<string, string>, 1: bool} The outputs, and whether a list failed.
	 */
	private static function join_lists( array $field_texts, array $lists, array $current ): array {
		$failed = false;

		foreach ( $lists as $key => $count ) {
			$pieces = [];

			for ( $n = 0; $n < $count; $n++ ) {
				$piece = $key . self::LIST_PIECE . $n;

				if ( isset( $field_texts[ $piece ] ) ) {
					$pieces[] = $field_texts[ $piece ];
				}

				unset( $field_texts[ $piece ] );
			}

			$joined = KeywordList::join( $pieces, $count );

			if ( null === $joined ) {
				$failed = true;

				if ( is_string( $current[ $key ] ?? null ) && '' !== $current[ $key ] ) {
					$field_texts[ $key ] = $current[ $key ];
				}
				continue;
			}

			$field_texts[ $key ] = $joined;
		}

		return [ $field_texts, $failed ];
	}

	/**
	 * The provider format of a meta key, as MetaTranslator asks it: 'text', 'copy', 'keywords' or 'html'.
	 *
	 * @param string   $key    Meta key.
	 * @param \WP_Post $source Source.
	 * @return string
	 */
	private static function field_format( string $key, \WP_Post $source ): string {
		/** This filter is documented in src/MachineTranslation/MetaTranslator.php */
		$format = apply_filters( 'perflocale/mt/meta_key_format', 'html', $key, $source->post_type, $source->ID );

		return match ( $format ) {
			'text', \PerfLocale\MachineTranslation\MetaTranslator::FORMAT_COPY, KeywordList::FORMAT => $format,
			default => 'html',
		};
	}

	/**
	 * The flags, sorted, each once.
	 *
	 * @param array<int, string> $flags Flags.
	 * @return list<string>
	 */
	private static function flag_list( array $flags ): array {
		$flags = array_values( array_unique( $flags ) );
		sort( $flags, SORT_STRING );

		return $flags;
	}

	/**
	 * A decoded value as a string => string map.
	 *
	 * @param mixed $value Value.
	 * @return array<string, string>
	 */
	private static function string_map( mixed $value ): array {
		$out = [];

		foreach ( is_array( $value ) ? $value : [] as $key => $item ) {
			if ( is_string( $item ) ) {
				$out[ (string) $key ] = $item;
			}
		}

		return $out;
	}

	/**
	 * The integer counts of a summary or a count map.
	 *
	 * @param array<string, mixed> $values Values.
	 * @return array<string, int>
	 */
	private static function int_map( array $values ): array {
		$out = [];

		foreach ( $values as $key => $value ) {
			if ( is_int( $value ) ) {
				$out[ (string) $key ] = $value;
			}
		}

		return $out;
	}
}

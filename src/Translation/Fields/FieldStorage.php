<?php
/**
 * Where a host keeps a field's value.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Translation\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read and write one key of one object wherever the host stores it. Reads return rows: Meta Box storage cannot
 * tell a scalar from a one-item `multiple` list, so the consumer applies the descriptor's `multiple` flag.
 */
interface FieldStorage {

	public const WRITTEN   = 'written';
	public const UNCHANGED = 'unchanged';
	public const CONFLICT  = 'conflict';
	public const FAILED    = 'failed';

	/**
	 * The rows a key holds, [] when absent.
	 *
	 * @param int|string $object_id Object id.
	 * @param string     $key       Key.
	 * @return array<int, mixed>
	 */
	public function read( int|string $object_id, string $key ): array;

	/**
	 * Write one value over the rows the caller read: compare-and-set for every previous value, empty ones included.
	 * A value stored after the read is never overwritten, a deleted row never re-added, an absent key only added.
	 *
	 * @param int|string        $object_id Object id.
	 * @param string            $key       Key.
	 * @param mixed             $value     Value (unslashed).
	 * @param array<int, mixed> $prev_rows The key's rows as read ([] = absent).
	 * @return string `written`, `unchanged`, `conflict` (another writer changed the key first) or `failed`.
	 */
	public function write( int|string $object_id, string $key, mixed $value, array $prev_rows ): string;
}

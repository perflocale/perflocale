<?php
/**
 * Repository interface.
 *
 * @package PerfLocale
 */

declare( strict_types=1 );

namespace PerfLocale\Contract;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base contract for all data repositories.
 */
interface RepositoryInterface {

	/**
	 * Find a record by its primary key.
	 *
	 * @param int $id Record ID.
	 * @return object|null
	 */
	public function find( int $id ): ?object;

	/**
	 * Find all records matching given criteria.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<int, object>
	 */
	public function find_all( array $args = [] ): array;

	/**
	 * Insert a new record.
	 *
	 * @param array<string, mixed> $data Column data.
	 * @return int|false Inserted ID or false on failure.
	 */
	public function insert( array $data ): int|false;

	/**
	 * Update an existing record.
	 *
	 * @param int                  $id   Record ID.
	 * @param array<string, mixed> $data Column data to update.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool;

	/**
	 * Delete a record by ID.
	 *
	 * @param int $id Record ID.
	 * @return bool
	 */
	public function delete( int $id ): bool;
}

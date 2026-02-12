<?php
/**
 * DatabaseManager Tests
 *
 * @package SellMyImages\Tests
 */

namespace SellMyImages\Tests\Unit\Managers;

use Brain\Monkey\Functions;
use SellMyImages\Managers\DatabaseManager;
use Mockery;

class DatabaseManagerTest extends \SMI_TestCase {

    private $wpdb_mock;

    protected function setUp(): void {
        parent::setUp();

        // Create wpdb mock
        $this->wpdb_mock              = Mockery::mock( 'wpdb' );
        $this->wpdb_mock->prefix      = 'wp_';
        $this->wpdb_mock->last_error  = '';
        $this->wpdb_mock->insert_id   = 0;

        // Set global wpdb
        $GLOBALS['wpdb'] = $this->wpdb_mock;
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'] );
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @test
     */
    public function get_jobs_table_returns_prefixed_name(): void {
        $result = DatabaseManager::get_jobs_table();

        $this->assertEquals( 'wp_smi_jobs', $result );
    }

    /**
     * @test
     */
    public function insert_returns_false_for_empty_data(): void {
        $result = DatabaseManager::insert( array() );

        $this->assertFalse( $result );
    }

    /**
     * @test
     */
    public function insert_returns_id_on_success(): void {
        $this->wpdb_mock->insert_id = 42;

        $this->wpdb_mock
            ->shouldReceive( 'insert' )
            ->once()
            ->andReturn( 1 );

        $result = DatabaseManager::insert(
            array(
                'job_id'    => 'test-uuid',
                'image_url' => 'https://example.com/image.jpg',
            )
        );

        $this->assertIsArray( $result );
        $this->assertEquals( 42, $result['id'] );
        $this->assertEquals( 1, $result['rows_affected'] );
    }

    /**
     * @test
     */
    public function insert_returns_false_on_database_failure(): void {
        $this->wpdb_mock
            ->shouldReceive( 'insert' )
            ->once()
            ->andReturn( false );

        $result = DatabaseManager::insert(
            array(
                'job_id' => 'test-uuid',
            )
        );

        $this->assertFalse( $result );
    }

    /**
     * @test
     */
    public function update_returns_false_for_empty_data(): void {
        $result = DatabaseManager::update( array(), array( 'job_id' => 'test' ) );

        $this->assertFalse( $result );
    }

    /**
     * @test
     */
    public function update_returns_false_for_empty_where(): void {
        $result = DatabaseManager::update( array( 'status' => 'completed' ), array() );

        $this->assertFalse( $result );
    }

    /**
     * @test
     */
    public function update_returns_true_on_success(): void {
        $this->wpdb_mock
            ->shouldReceive( 'update' )
            ->once()
            ->andReturn( 1 );

        $result = DatabaseManager::update(
            array( 'status' => 'completed' ),
            array( 'job_id' => 'test-uuid' )
        );

        $this->assertTrue( $result );
    }

    /**
     * @test
     */
    public function update_returns_true_even_when_no_rows_affected(): void {
        // wpdb::update returns 0 when no rows matched, but this is still successful
        $this->wpdb_mock
            ->shouldReceive( 'update' )
            ->once()
            ->andReturn( 0 );

        $result = DatabaseManager::update(
            array( 'status' => 'completed' ),
            array( 'job_id' => 'nonexistent' )
        );

        // 0 is not false, so should return true
        $this->assertTrue( $result );
    }

    /**
     * @test
     */
    public function delete_returns_false_for_empty_where(): void {
        $result = DatabaseManager::delete( array() );

        $this->assertFalse( $result );
    }

    /**
     * @test
     */
    public function delete_returns_count_on_success(): void {
        $this->wpdb_mock
            ->shouldReceive( 'delete' )
            ->once()
            ->andReturn( 1 );

        $result = DatabaseManager::delete( array( 'job_id' => 'test-uuid' ) );

        $this->assertEquals( 1, $result );
    }

    /**
     * @test
     */
    public function get_row_returns_null_for_empty_where(): void {
        $result = DatabaseManager::get_row( array() );

        $this->assertNull( $result );
    }

    /**
     * @test
     */
    public function get_row_returns_job_object(): void {
        $expected_job = (object) array(
            'id'     => 1,
            'job_id' => 'test-uuid',
            'status' => 'pending',
        );

        $this->wpdb_mock
            ->shouldReceive( 'prepare' )
            ->once()
            ->andReturn( 'SELECT * FROM wp_smi_jobs WHERE job_id = "test-uuid"' );

        $this->wpdb_mock
            ->shouldReceive( 'get_row' )
            ->once()
            ->andReturn( $expected_job );

        $result = DatabaseManager::get_row( array( 'job_id' => 'test-uuid' ) );

        $this->assertEquals( $expected_job, $result );
    }

    /**
     * @test
     */
    public function get_results_returns_empty_array_when_no_results(): void {
        Functions\when( 'sanitize_sql_orderby' )
            ->justReturn( 'created_at DESC' );

        $this->wpdb_mock
            ->shouldReceive( 'prepare' )
            ->once()
            ->andReturn( 'SELECT * FROM wp_smi_jobs WHERE status = "completed" ORDER BY created_at DESC' );

        $this->wpdb_mock
            ->shouldReceive( 'get_results' )
            ->once()
            ->andReturn( null );

        $result = DatabaseManager::get_results( array( 'where' => array( 'status' => 'completed' ) ) );

        $this->assertIsArray( $result );
        $this->assertEmpty( $result );
    }

    /**
     * @test
     */
    public function get_count_returns_integer(): void {
        $this->wpdb_mock
            ->shouldReceive( 'prepare' )
            ->once()
            ->andReturn( 'SELECT COUNT(*) FROM wp_smi_jobs WHERE status = "pending"' );

        $this->wpdb_mock
            ->shouldReceive( 'get_var' )
            ->once()
            ->andReturn( '5' );

        $result = DatabaseManager::get_count( array( 'status' => 'pending' ) );

        $this->assertIsInt( $result );
        $this->assertEquals( 5, $result );
    }

    /**
     * @test
     */
    public function get_count_returns_zero_for_empty_table(): void {
        $this->wpdb_mock
            ->shouldReceive( 'get_var' )
            ->once()
            ->andReturn( '0' );

        $result = DatabaseManager::get_count();

        $this->assertEquals( 0, $result );
    }

    /**
     * @test
     */
    public function jobs_table_constant_is_defined(): void {
        $this->assertEquals( 'smi_jobs', DatabaseManager::JOBS_TABLE );
    }

    /**
     * @test
     */
    public function cleanup_abandoned_jobs_sweeps_stale_awaiting_payment(): void {
        $stale_job = (object) array( 'job_id' => 'stale-uuid-1' );

        Functions\when( 'get_option' )->justReturn( 24 );
        Functions\when( 'gmdate' )->justReturn( '2025-01-01 00:00:00' );

        // First query: stale awaiting_payment jobs
        $this->wpdb_mock
            ->shouldReceive( 'prepare' )
            ->andReturnUsing( function () {
                return 'PREPARED_QUERY';
            } );

        $this->wpdb_mock
            ->shouldReceive( 'get_results' )
            ->twice()
            ->andReturn( array( $stale_job ), array() );

        // Should update the stale job to abandoned
        $this->wpdb_mock
            ->shouldReceive( 'update' )
            ->once()
            ->with(
                'wp_smi_jobs',
                array( 'status' => 'abandoned' ),
                array( 'job_id' => 'stale-uuid-1' ),
                array( '%s' ),
                array( '%s' )
            )
            ->andReturn( 1 );

        // No abandoned jobs to delete
        $this->wpdb_mock
            ->shouldReceive( 'delete' )
            ->never();

        $result = DatabaseManager::cleanup_abandoned_jobs();

        $this->assertEquals( 0, $result );
    }

    /**
     * @test
     */
    public function cleanup_abandoned_jobs_deletes_old_abandoned_jobs(): void {
        $abandoned_job = (object) array(
            'job_id'             => 'abandoned-uuid-1',
            'upscaled_file_path' => '',
        );

        Functions\when( 'get_option' )->justReturn( 24 );
        Functions\when( 'gmdate' )->justReturn( '2025-01-01 00:00:00' );

        $this->wpdb_mock
            ->shouldReceive( 'prepare' )
            ->andReturn( 'PREPARED_QUERY' );

        // First call: no stale awaiting_payment; second call: one abandoned job
        $this->wpdb_mock
            ->shouldReceive( 'get_results' )
            ->twice()
            ->andReturn( array(), array( $abandoned_job ) );

        $this->wpdb_mock
            ->shouldReceive( 'update' )
            ->never();

        $this->wpdb_mock
            ->shouldReceive( 'delete' )
            ->once()
            ->with(
                'wp_smi_jobs',
                array( 'job_id' => 'abandoned-uuid-1' ),
                array( '%s' )
            )
            ->andReturn( 1 );

        $result = DatabaseManager::cleanup_abandoned_jobs();

        $this->assertEquals( 1, $result );
    }

    /**
     * @test
     */
    public function cleanup_abandoned_jobs_ignores_recent_awaiting_payment(): void {
        Functions\when( 'get_option' )->justReturn( 24 );
        Functions\when( 'gmdate' )->justReturn( '2025-01-01 00:00:00' );

        $this->wpdb_mock
            ->shouldReceive( 'prepare' )
            ->andReturn( 'PREPARED_QUERY' );

        // Both queries return empty (recent jobs not included by SQL WHERE)
        $this->wpdb_mock
            ->shouldReceive( 'get_results' )
            ->twice()
            ->andReturn( array(), array() );

        $this->wpdb_mock
            ->shouldReceive( 'update' )
            ->never();

        $this->wpdb_mock
            ->shouldReceive( 'delete' )
            ->never();

        $result = DatabaseManager::cleanup_abandoned_jobs();

        $this->assertEquals( 0, $result );
    }
}

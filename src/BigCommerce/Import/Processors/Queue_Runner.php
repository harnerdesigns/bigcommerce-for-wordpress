<?php


namespace BigCommerce\Import\Processors;


use BigCommerce\Api\v3\Api\CatalogApi;
use BigCommerce\Api\v3\Api\ChannelsApi;
use BigCommerce\Api\v3\Model\Listing;
use BigCommerce\Api\v3\Model\Product;
use BigCommerce\Api\v3\ObjectSerializer;
use BigCommerce\Import\Importers\Products\Product_Importer;
use BigCommerce\Import\Importers\Products\Product_Remover;
use BigCommerce\Import\Runner\Status;
use BigCommerce\Logging\Error_Log;
use BigCommerce\Settings\Sections\Channels;

class Queue_Runner implements Import_Processor {
	/**
	 * @var CatalogApi Catalog API instance used for importing products
	 */
	private $catalog;

	/**
	 * @var ChannelsApi Channels API instance used for importing products
	 */
	private $channels;

	/**
	 * @var int Number of items to process from the queue per batch
	 */
	private $batch;

	/**
	 * @var int Maximum number of times to attempt to import a product before giving up on it
	 */
	private $max_attempts;

	/**
	 * Queue_Runner constructor.
	 *
	 * @param CatalogApi  $catalog
	 * @param ChannelsApi $channels
	 * @param int         $batch
	 * @param int         $max_attempts
	 */
	public function __construct( CatalogApi $catalog, ChannelsApi $channels, $batch = 5, $max_attempts = 10 ) {
		$this->catalog      = $catalog;
		$this->channels     = $channels;
		$this->batch        = (int) $batch;
		$this->max_attempts = (int) $max_attempts;
	}

	public function run() {
		$status = new Status();
		$status->set_status( Status::PROCESSING_QUEUE );

		$channel_id = get_option( Channels::CHANNEL_ID, 0 );
		if ( empty( $channel_id ) ) {
			do_action( 'bigcommerce/import/error', __( 'Channel ID is not set. Product import canceled.', 'bigcommerce' ) );

			return;
		}

		/** @var \wpdb $wpdb */
		global $wpdb;

		$query   = "SELECT * FROM {$wpdb->bc_import_queue} ORDER BY attempts ASC, priority DESC, date_created ASC, date_modified ASC LIMIT {$this->batch}";
		$records = $wpdb->get_results( $query );

		foreach ( $records as $import ) {
			if ( function_exists( 'set_time_limit' ) && false === strpos( ini_get( 'disable_functions' ), 'set_time_limit' ) && ! ini_get( 'safe_mode' ) ) {
				@set_time_limit( 60 );
			}

			$wpdb->update(
				$wpdb->bc_import_queue,
				[ 'attempts' => $import->attempts + 1, 'last_attempt' => date( 'Y-m-d H:i:s' ), ],
				[ 'bc_id' => $import->bc_id ],
				[ '%d', '%s' ],
				[ '%d' ]
			);

			do_action( 'bigcommerce/log', Error_Log::DEBUG, __( 'Handling product from import queue', 'bigcommerce' ), [
				'product_id' => $import->bc_id,
				'attempt'    => $import->attempts,
				'action'     => $import->import_action,
			] );

			switch ( $import->import_action ) {
				case 'update':
				case 'ignore':
					/** @var Product $product */
					$product = ObjectSerializer::deserialize( json_decode( $import->product_data ), Product::class );
					/** @var Listing $listing */
					$listing = ObjectSerializer::deserialize( json_decode( $import->listing_data ), Listing::class );

					if ( ! $product || ! $listing ) {
						do_action( 'bigcommerce/log', Error_Log::WARNING, __( 'Unable to parse product data, removing from queue', 'bigcommerce' ), [
							'product_id' => $import->bc_id,
						] );
						$wpdb->delete( $wpdb->bc_import_queue, [ 'bc_id' => $import->bc_id ], [ '%d' ] );
					} else {
						$importer = new Product_Importer( $product, $listing, $this->catalog, $this->channels, $channel_id );
						$post_id  = $importer->import();
						if ( ! empty( $post_id ) || $import->attempts > $this->max_attempts ) {
							if ( ! empty( $post_id ) ) {
								do_action( 'bigcommerce/log', Error_Log::DEBUG, __( 'Product imported successfully', 'bigcommerce' ), [
									'product_id' => $import->bc_id,
									'action'     => $import->import_action,
								] );
							} else {
								do_action( 'bigcommerce/log', Error_Log::WARNING, __( 'Too many failed attempts, removing from queue', 'bigcommerce' ), [
									'product_id' => $import->bc_id,
									'action'     => $import->import_action,
								] );
							}
							$wpdb->delete( $wpdb->bc_import_queue, [ 'bc_id' => $import->bc_id ], [ '%d' ] );
						}
					}
					break;
				case 'delete':
					$remover = new Product_Remover( $import->bc_id );
					$remover->remove();
					$wpdb->delete( $wpdb->bc_import_queue, [ 'bc_id' => $import->bc_id ], [ '%d' ] );
					break;
				default:
					// how did we get here?
					do_action( 'bigcommerce/log', Error_Log::NOTICE, __( 'Unexpected import action', 'bigcommerce' ), [
						'product_id' => $import->bc_id,
						'action'     => $import->import_action,
					] );
					$wpdb->delete( $wpdb->bc_import_queue, [ 'bc_id' => $import->bc_id ], [ '%d' ] );
					break;
			}

		}

		$remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->bc_import_queue}" );
		do_action( 'bigcommerce/log', Error_Log::DEBUG, __( 'Completed import bach', 'bigcommerce' ), [
			'count'     => count( $records ),
			'remaining' => $remaining,
		] );
		if ( $remaining < 1 ) {
			$status->set_status( Status::PROCESSED_QUEUE );
		}

	}
}
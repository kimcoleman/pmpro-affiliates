<?php
/**
 * Payout CSV generation and streaming.
 * Called via wp_ajax_pmpro_affiliates_payout_csv.
 *
 * Accepts:
 *  - payout_ids: comma-separated string or array of payout IDs
 *  - format: 'summary' (default) or 'paypal'
 */

// Permissions check.
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'pmpro-affiliates' ) );
}

// Nonce check.
check_ajax_referer( 'pmpro_affiliates_payout_csv' );

// Parse and validate payout IDs.
$raw_ids = isset( $_POST['payout_ids'] ) ? $_POST['payout_ids'] : '';
if ( is_array( $raw_ids ) ) {
	$payout_ids = array_map( 'intval', $raw_ids );
} else {
	$payout_ids = array_map( 'intval', explode( ',', sanitize_text_field( $raw_ids ) ) );
}
$payout_ids = array_filter( $payout_ids );

if ( empty( $payout_ids ) ) {
	wp_die( esc_html__( 'No payout IDs provided.', 'pmpro-affiliates' ) );
}

$format = isset( $_POST['format'] ) && 'paypal' === $_POST['format'] ? 'paypal' : 'summary';

// Load payout records.
$payouts = array();
foreach ( $payout_ids as $pid ) {
	$p = pmpro_affiliates_get_payout( $pid );
	if ( $p ) {
		$payouts[] = $p;
	}
}

if ( empty( $payouts ) ) {
	wp_die( esc_html__( 'No payout records found for the provided IDs.', 'pmpro-affiliates' ) );
}

// Determine date range string for filename (use the span across all payouts).
$all_starts = wp_list_pluck( $payouts, 'date_start' );
$all_ends   = wp_list_pluck( $payouts, 'date_end' );
sort( $all_starts );
sort( $all_ends );
$range_start = str_replace( ' ', '-', preg_replace( '/[^0-9-]/', '', $all_starts[0] ) );
$range_end   = str_replace( ' ', '-', preg_replace( '/[^0-9-]/', '', end( $all_ends ) ) );

if ( 'paypal' === $format ) {
	$filename = 'affiliate-payouts-paypal-' . date( 'Y-m-d' ) . '.csv';
} else {
	$filename = 'affiliate-payouts-' . $range_start . '-to-' . $range_end . '.csv';
}

// Stream headers.
header( 'Content-Type: text/csv; charset=UTF-8' );
header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
header( 'Pragma: no-cache' );
header( 'Expires: 0' );

$output = fopen( 'php://output', 'w' );

// UTF-8 BOM for Excel compatibility.
fputs( $output, "\xEF\xBB\xBF" );

if ( 'paypal' === $format ) {
	// PayPal Mass Pay CSV format.
	fputcsv( $output, array( 'email', 'amount', 'currency', 'note' ) );

	foreach ( $payouts as $payout ) {
		if ( 'paypal' !== $payout->payout_method || empty( $payout->payout_account ) ) {
			continue;
		}

		$note = sprintf(
			/* translators: 1: date start, 2: date end */
			__( 'Affiliate commission payout %1$s to %2$s', 'pmpro-affiliates' ),
			substr( $payout->date_start, 0, 10 ),
			substr( $payout->date_end, 0, 10 )
		);

		fputcsv( $output, array(
			$payout->payout_account,
			number_format( (float) $payout->amount, 2, '.', '' ),
			pmpro_getOption( 'currency' ) ?: 'USD',
			$note,
		) );
	}
} else {
	// Payout Summary CSV.
	fputcsv( $output, array(
		'payout_id',
		'affiliate_id',
		'affiliate_name',
		'affiliate_code',
		'payout_email',
		'payout_method',
		'amount',
		'order_count',
		'order_ids',
		'date_start',
		'date_end',
		'date',
	) );

	foreach ( $payouts as $payout ) {
		// Get affiliate details.
		global $wpdb;
		$affiliate = $wpdb->get_row( $wpdb->prepare(
			"SELECT name, code FROM {$wpdb->pmpro_affiliates} WHERE id = %d",
			(int) $payout->affiliate_id
		) );

		$order_ids   = array_filter( array_map( 'intval', explode( ',', $payout->order_ids ) ) );
		$order_count = count( $order_ids );

		fputcsv( $output, array(
			$payout->id,
			$payout->affiliate_id,
			$affiliate ? stripslashes( $affiliate->name ) : '',
			$affiliate ? $affiliate->code : '',
			$payout->payout_account,
			$payout->payout_method,
			number_format( (float) $payout->amount, 2, '.', '' ),
			$order_count,
			$payout->order_ids,
			substr( $payout->date_start, 0, 10 ),
			substr( $payout->date_end, 0, 10 ),
			substr( $payout->date, 0, 10 ),
		) );
	}
}

fclose( $output );
exit;

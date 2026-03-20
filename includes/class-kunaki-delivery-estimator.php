<?php
defined( 'ABSPATH' ) || exit;

class Kunaki_Delivery_Estimator {

	const STATS_TRANSIENT_KEY = 'kunaki_manufacturing_stats';
	const STATS_TRANSIENT_TTL = 6 * HOUR_IN_SECONDS;
	const MIN_SAMPLE_SIZE     = 5;
	const MAX_SAMPLE_SIZE     = 100;

	const META_SUBMITTED_AT = '_kunaki_submitted_at';
	const META_SHIPPED_AT   = '_kunaki_shipped_at';

	/**
	 * Get cached manufacturing time statistics.
	 *
	 * @return array{mean_days: float, p25_days: float, p75_days: float, sample_size: int}|null
	 */
	public function get_manufacturing_stats() {
		$cached = get_transient( self::STATS_TRANSIENT_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$stats = $this->compute_manufacturing_stats();
		if ( null !== $stats ) {
			set_transient( self::STATS_TRANSIENT_KEY, $stats, self::STATS_TRANSIENT_TTL );
		}

		return $stats;
	}

	/**
	 * Estimate delivery date range for a shipping option.
	 *
	 * Combines historical manufacturing time with parsed transit time
	 * to produce a calendar date range the customer can expect.
	 *
	 * @param string $delivery_time Delivery time string from Kunaki API (e.g. "5-10 Business Days").
	 * @return array{earliest: int, latest: int, label: string}|null Null if insufficient historical data.
	 */
	public function estimate_delivery( $delivery_time ) {
		$stats = $this->get_manufacturing_stats();
		if ( null === $stats ) {
			return null;
		}

		$transit   = $this->parse_transit_days( $delivery_time );
		$min_total = max( 1, (int) ceil( $stats['p25_days'] + $transit[0] ) );
		$max_total = max( $min_total, (int) ceil( $stats['p75_days'] + $transit[1] ) );

		$now      = time();
		$earliest = $now + $min_total * DAY_IN_SECONDS;
		$latest   = $now + $max_total * DAY_IN_SECONDS;

		return array(
			'earliest' => $earliest,
			'latest'   => $latest,
			'label'    => $this->format_date_range( $earliest, $latest ),
		);
	}

	/**
	 * Compute manufacturing statistics from historical order data.
	 *
	 * Queries orders that have both a submitted and shipped timestamp,
	 * then computes mean, P25, and P75 of the manufacturing duration.
	 *
	 * @return array{mean_days: float, p25_days: float, p75_days: float, sample_size: int}|null
	 */
	private function compute_manufacturing_stats() {
		$orders = wc_get_orders( array(
			'meta_query' => array(
				array( 'key' => self::META_SUBMITTED_AT, 'compare' => 'EXISTS' ),
				array( 'key' => self::META_SHIPPED_AT, 'compare' => 'EXISTS' ),
			),
			'limit'   => self::MAX_SAMPLE_SIZE,
			'orderby' => 'date',
			'order'   => 'DESC',
		) );

		$durations = array();
		foreach ( $orders as $order ) {
			$submitted = strtotime( $order->get_meta( self::META_SUBMITTED_AT ) );
			$shipped   = strtotime( $order->get_meta( self::META_SHIPPED_AT ) );
			if ( $submitted && $shipped && $shipped > $submitted ) {
				$durations[] = ( $shipped - $submitted ) / DAY_IN_SECONDS;
			}
		}

		if ( count( $durations ) < self::MIN_SAMPLE_SIZE ) {
			return null;
		}

		sort( $durations );

		$count = count( $durations );
		$mean  = array_sum( $durations ) / $count;
		$p25   = $durations[ (int) floor( $count * 0.25 ) ];
		$p75   = $durations[ (int) floor( $count * 0.75 ) ];

		return array(
			'mean_days'   => round( $mean, 1 ),
			'p25_days'    => round( $p25, 1 ),
			'p75_days'    => round( $p75, 1 ),
			'sample_size' => $count,
		);
	}

	/**
	 * Parse a Kunaki delivery time string into a calendar day range.
	 *
	 * Handles formats like "5 days", "2-3 Business Days", "1 day".
	 * Business days are converted to approximate calendar days (x 7/5).
	 *
	 * @param string $delivery_time Delivery time string from Kunaki API.
	 * @return int[] Array of [min_days, max_days] in calendar days.
	 */
	public function parse_transit_days( $delivery_time ) {
		$is_business = false !== stripos( $delivery_time, 'business' );

		if ( preg_match( '/(\d+)\s*[-\x{2013}]\s*(\d+)/u', $delivery_time, $matches ) ) {
			$min = (int) $matches[1];
			$max = (int) $matches[2];
		} elseif ( preg_match( '/(\d+)/', $delivery_time, $matches ) ) {
			$min = (int) $matches[1];
			$max = $min;
		} else {
			return array( 3, 10 );
		}

		if ( $is_business ) {
			$min = (int) ceil( $min * 7 / 5 );
			$max = (int) ceil( $max * 7 / 5 );
		}

		return array( $min, $max );
	}

	/**
	 * Format a date range for display.
	 *
	 * Returns "Mar 25" if both dates are the same day, or "Mar 25 – Mar 28"
	 * for a range. Uses the site's date locale via wp_date().
	 *
	 * @param int $earliest Unix timestamp.
	 * @param int $latest   Unix timestamp.
	 * @return string Formatted date range.
	 */
	public function format_date_range( $earliest, $latest ) {
		$fmt_early = wp_date( 'M j', $earliest );
		$fmt_late  = wp_date( 'M j', $latest );

		if ( false === $fmt_early || false === $fmt_late ) {
			return '';
		}

		if ( $fmt_early === $fmt_late ) {
			return $fmt_early;
		}

		return $fmt_early . "\xE2\x80\x93" . $fmt_late;
	}
}

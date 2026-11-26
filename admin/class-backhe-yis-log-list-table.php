<?php
/**
 * Activity log list table.
 *
 * @package Admin_Backend_and_Update_Helper_YIS_Media
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Displays the activity log with filters and pagination.
 *
 * @since 1.1.0
 */
class Backhe_YIS_Log_List_Table extends WP_List_Table {

	/**
	 * Entries per page.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	const PER_PAGE = 50;

	/**
	 * Activity log.
	 *
	 * @since 1.1.0
	 * @var Backhe_YIS_Activity_Log
	 */
	private $log;

	/**
	 * Active filters.
	 *
	 * @since 1.1.0
	 * @var array
	 */
	private $filters;

	/**
	 * Set up the table.
	 *
	 * @since 1.1.0
	 *
	 * @param Backhe_YIS_Activity_Log $log     Activity log.
	 * @param array                   $filters Filters with section, from and to (Y-m-d).
	 */
	public function __construct( Backhe_YIS_Activity_Log $log, array $filters ) {
		parent::__construct(
			array(
				'singular' => 'backhe-yis-log-entry',
				'plural'   => 'backhe-yis-log-entries',
				'ajax'     => false,
			)
		);

		$this->log     = $log;
		$this->filters = $filters;
	}

	/**
	 * Get the columns.
	 *
	 * @since 1.1.0
	 *
	 * @return string[] Column labels keyed by column.
	 */
	public function get_columns() {
		return array(
			'created_at'  => __( 'Date', 'admin-backend-and-update-helper' ),
			'event_type'  => __( 'Action', 'admin-backend-and-update-helper' ),
			'object_name' => __( 'Item', 'admin-backend-and-update-helper' ),
			'details'     => __( 'Details', 'admin-backend-and-update-helper' ),
			'user_id'     => __( 'By', 'admin-backend-and-update-helper' ),
		);
	}

	/**
	 * Load the entries for the current page.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'created_at' );

		$args  = $this->get_query_args();
		$total = $this->log->count( $args );

		$this->items = $this->log->query(
			array_merge(
				$args,
				array(
					'limit'  => self::PER_PAGE,
					'offset' => ( $this->get_pagenum() - 1 ) * self::PER_PAGE,
				)
			)
		);

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Message shown when there are no entries.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No entries found.', 'admin-backend-and-update-helper' );
	}

	/**
	 * Render the date column with row actions for manual entries.
	 *
	 * @since 1.1.0
	 *
	 * @param array $item Entry.
	 * @return string Cell HTML.
	 */
	public function column_created_at( $item ) {
		$date = esc_html( get_date_from_gmt( $item['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );

		if ( 'manual' === $item['source'] ) {
			$date = esc_html( get_date_from_gmt( $item['created_at'], get_option( 'date_format' ) ) );
		}

		if ( 'manual' !== $item['source'] ) {
			return $date;
		}

		$edit_url   = add_query_arg(
			array(
				'page' => 'backhe_yis_log',
				'edit' => $item['id'],
			),
			admin_url( 'admin.php' )
		);
		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'backhe_yis_log_delete_manual',
					'id'     => $item['id'],
				),
				admin_url( 'admin-post.php' )
			),
			'backhe_yis_log_delete_' . $item['id']
		);

		$actions = array(
			'edit'   => sprintf(
				'<a href="%1$s">%2$s<span class="screen-reader-text"> %3$s</span></a>',
				esc_url( $edit_url ),
				esc_html__( 'Edit', 'admin-backend-and-update-helper' ),
				esc_html( $item['object_name'] )
			),
			'delete' => sprintf(
				'<a href="%1$s" class="backhe-delete-entry">%2$s<span class="screen-reader-text"> %3$s</span></a>',
				esc_url( $delete_url ),
				esc_html__( 'Delete', 'admin-backend-and-update-helper' ),
				esc_html( $item['object_name'] )
			),
		);

		return $date . $this->row_actions( $actions );
	}

	/**
	 * Render the action column.
	 *
	 * @since 1.1.0
	 *
	 * @param array $item Entry.
	 * @return string Cell HTML.
	 */
	public function column_event_type( $item ) {
		$labels = Backhe_YIS_Event_Tracker::get_event_labels();

		return esc_html( $labels[ $item['event_type'] ] ?? $item['event_type'] );
	}

	/**
	 * Render the item column.
	 *
	 * @since 1.1.0
	 *
	 * @param array $item Entry.
	 * @return string Cell HTML.
	 */
	public function column_object_name( $item ) {
		$name = esc_html( $item['object_name'] );

		if ( $item['object_id'] > 0 && 'user' !== $item['object_type'] && 'manual' !== $item['source'] ) {
			$link = get_edit_post_link( $item['object_id'] );

			if ( $link ) {
				$name = '<a href="' . esc_url( $link ) . '">' . $name . '</a>';
			}
		}

		if ( ! empty( $item['details']['post_type_label'] ) ) {
			$name .= ' <span class="description">(' . esc_html( $item['details']['post_type_label'] ) . ')</span>';
		}

		return $name;
	}

	/**
	 * Render the details column.
	 *
	 * @since 1.1.0
	 *
	 * @param array $item Entry.
	 * @return string Cell HTML.
	 */
	public function column_details( $item ) {
		if ( 'manual' === $item['source'] ) {
			return esc_html( Backhe_YIS_Report::format_duration( (int) $item['duration_minutes'] ) );
		}

		return esc_html( Backhe_YIS_Report::describe_details( $item ) );
	}

	/**
	 * Render the user column.
	 *
	 * @since 1.1.0
	 *
	 * @param array $item Entry.
	 * @return string Cell HTML.
	 */
	public function column_user_id( $item ) {
		if ( 0 === $item['user_id'] ) {
			return esc_html__( 'System', 'admin-backend-and-update-helper' );
		}

		$user = get_userdata( $item['user_id'] );

		return esc_html( $user ? $user->display_name : '#' . $item['user_id'] );
	}

	/**
	 * Build the query arguments from the filters.
	 *
	 * @since 1.1.0
	 *
	 * @return array Query arguments for Backhe_YIS_Activity_Log::query().
	 */
	private function get_query_args() {
		$args = array();
		$utc  = new DateTimeZone( 'UTC' );

		if ( ! empty( $this->filters['from'] ) ) {
			$from = DateTimeImmutable::createFromFormat( '!Y-m-d', $this->filters['from'], wp_timezone() );

			if ( $from ) {
				$args['from'] = $from->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
			}
		}

		if ( ! empty( $this->filters['to'] ) ) {
			$to = DateTimeImmutable::createFromFormat( '!Y-m-d', $this->filters['to'], wp_timezone() );

			if ( $to ) {
				$args['to'] = $to->modify( '+1 day' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
			}
		}

		$sections = Backhe_YIS_Report::get_sections();

		if ( isset( $sections[ $this->filters['section'] ] ) ) {
			$args['event_types'] = $sections[ $this->filters['section'] ]['events'];
		}

		return $args;
	}
}

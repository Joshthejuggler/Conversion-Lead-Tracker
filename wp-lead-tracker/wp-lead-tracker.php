<?php
/**
 * Plugin Name:       WP Lead Tracker
 * Description:       Tracks lead source information and events, displaying them in the WP dashboard.
 * Version:           1.0.0
 * Author:            Gemini Code Assist
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-lead-tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'WPLT_TABLE_NAME', 'wplt_lead_events' );

/**
 * Create a custom database table on plugin activation.
 */
function wplt_on_activate() {
	global $wpdb;
	$table_name      = $wpdb->prefix . WPLT_TABLE_NAME;
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		event_time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
		event_type varchar(255) NOT NULL,
		event_label varchar(255) NOT NULL,
		traffic_type varchar(50) DEFAULT '' NOT NULL,
		device_type varchar(50) DEFAULT '' NOT NULL,
		utm_source varchar(255) DEFAULT '' NOT NULL,
		utm_medium varchar(255) DEFAULT '' NOT NULL,
		utm_campaign varchar(255) DEFAULT '' NOT NULL,
		utm_term varchar(255) DEFAULT '' NOT NULL,
		ad_id varchar(255) DEFAULT '' NOT NULL,
		entry_url text NOT NULL,
		submitting_url text NOT NULL,
		page_location text NOT NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}
register_activation_hook( __FILE__, 'wplt_on_activate' );

/**
 * Clear scheduled events on plugin deactivation.
 */
function wplt_on_deactivate() {
	wp_clear_scheduled_hook( 'wplt_monthly_report_event' );
}
register_deactivation_hook( __FILE__, 'wplt_on_deactivate' );

/**
 * Add the admin menu page for the dashboard.
 */
function wplt_admin_menu() {
	add_menu_page(
		__( 'Lead Tracker', 'wp-lead-tracker' ),
		__( 'Lead Tracker', 'wp-lead-tracker' ),
		'manage_options',
		'wp-lead-tracker',
		'wplt_render_dashboard_page',
		'dashicons-chart-line',
		30
	);
}
add_action( 'admin_menu', 'wplt_admin_menu' );

// Load the WP_List_Table class if it's not already available.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Creates the sortable and paginated table for our lead events.
 */
class WPLT_Events_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			[
				'singular' => __( 'Lead Event', 'wp-lead-tracker' ),
				'plural'   => __( 'Lead Events', 'wp-lead-tracker' ),
				'ajax'     => false,
			]
		);
	}

	public function get_columns() {
		return [
			'event_time'     => __( 'Date', 'wp-lead-tracker' ),
			'event_type'     => __( 'Event Type', 'wp-lead-tracker' ),
			'event_label'    => __( 'Event Label', 'wp-lead-tracker' ),
			'traffic_type'   => __( 'Traffic Type', 'wp-lead-tracker' ),
			'utm_source'     => __( 'Source', 'wp-lead-tracker' ),
			'utm_medium'     => __( 'Medium', 'wp-lead-tracker' ),
			'utm_campaign'   => __( 'Campaign', 'wp-lead-tracker' ),
			'submitting_url' => __( 'Submitting URL', 'wp-lead-tracker' ),
		];
	}

	public function get_sortable_columns() {
		return [
			'event_time'   => [ 'event_time', 'desc' ],
			'event_type'   => [ 'event_type', false ],
			'traffic_type' => [ 'traffic_type', false ],
			'utm_source'   => [ 'utm_source', false ],
			'utm_medium'   => [ 'utm_medium', false ],
			'utm_campaign' => [ 'utm_campaign', false ],
		];
	}

	public function prepare_items() {
		global $wpdb;
		$table_name = $wpdb->prefix . WPLT_TABLE_NAME;

		// Date filter
		$where_clause    = '';
		$allowed_periods = [ '1', '7', '30', '90' ];
		if ( isset( $_GET['period'] ) && in_array( $_GET['period'], $allowed_periods, true ) ) {
			$period   = $_GET['period'];
			$timezone = new DateTimeZone( 'UTC' );
			$end_date = new DateTime( 'now', $timezone );
			$start_date = new DateTime( 'now', $timezone );
			if ( $period > 1 ) {
				$start_date->modify( '-' . ( $period - 1 ) . ' days' );
			}
			$start_date->setTime( 0, 0, 0 );
			$end_date->setTime( 23, 59, 59 );

			$where_clause = $wpdb->prepare(
				' WHERE event_time BETWEEN %s AND %s',
				$start_date->format( 'Y-m-d H:i:s' ),
				$end_date->format( 'Y-m-d H:i:s' )
			);
		}

		$per_page     = 25;
		$current_page = $this->get_pagenum();
		$total_items  = $wpdb->get_var( "SELECT COUNT(id) FROM {$table_name}" . $where_clause );

		$this->set_pagination_args(
			[
				'total_items' => (int) $total_items,
				'per_page'    => $per_page,
			]
		);

		$columns  = $this->get_columns();
		$hidden   = [];
		$sortable = $this->get_sortable_columns();
		$this->_column_headers = [ $columns, $hidden, $sortable ];

		$sortable_columns = $this->get_sortable_columns();
		$orderby = ! empty( $_REQUEST['orderby'] ) && array_key_exists( $_REQUEST['orderby'], $sortable_columns ) ? $_REQUEST['orderby'] : 'event_time';
		$order   = ! empty( $_REQUEST['order'] ) && in_array( strtolower( $_REQUEST['order'] ), [ 'asc', 'desc' ], true ) ? strtolower( $_REQUEST['order'] ) : 'desc';
		$offset  = ( $current_page - 1 ) * $per_page;

		$query = "SELECT * FROM {$table_name}{$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$this->items = $wpdb->get_results( $wpdb->prepare( $query, $per_page, $offset ), ARRAY_A );
	}

	public function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}

	public function column_event_time( $item ) {
		return esc_html( gmdate( 'Y-m-d H:i:s', strtotime( $item['event_time'] ) ) );
	}

	public function column_submitting_url( $item ) {
		$url  = esc_url( $item['page_location'] );
		$text = esc_html( $item['submitting_url'] );
		return sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', $url, $text );
	}
}

/**
 * Render the dashboard page content.
 */
function wplt_render_dashboard_page() {
	global $wpdb;
	$table_name = $wpdb->prefix . WPLT_TABLE_NAME;

	// Notices
	if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'wp-lead-tracker' ) . '</p></div>';
	}
	if ( isset( $_GET['test-sent'] ) ) {
		$recipients = get_option( 'wplt_settings', [] )['email'] ?? '';
		if ( '1' === $_GET['test-sent'] && ! empty( $recipients ) ) {
			$message = sprintf(
				esc_html__( 'Test report successfully sent to: %s', 'wp-lead-tracker' ),
				'<strong>' . esc_html( $recipients ) . '</strong>'
			);
			echo '<div class="notice notice-success is-dismissible"><p>' . wp_kses_post( $message ) . '</p></div>';
		} elseif ( '0' === $_GET['test-sent'] && ! empty( $recipients ) ) {
			$message = sprintf(
				esc_html__( 'Failed to send test report to: %s. Please check your WordPress email settings (e.g., using an SMTP plugin).', 'wp-lead-tracker' ),
				'<strong>' . esc_html( $recipients ) . '</strong>'
			);
			echo '<div class="notice notice-error is-dismissible"><p>' . wp_kses_post( $message ) . '</p></div>';
		} else {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Cannot send test report: No recipient email address is saved.', 'wp-lead-tracker' ) . '</p></div>';
		}
	}
	if ( isset( $_GET['instant-test-sent'] ) ) {
		$recipients = get_option( 'wplt_settings', [] )['instant_email'] ?? '';
		if ( '1' === $_GET['instant-test-sent'] && ! empty( $recipients ) ) {
			$message = sprintf(
				esc_html__( 'Test instant notification successfully sent to: %s', 'wp-lead-tracker' ),
				'<strong>' . esc_html( $recipients ) . '</strong>'
			);
			echo '<div class="notice notice-success is-dismissible"><p>' . wp_kses_post( $message ) . '</p></div>';
		} elseif ( '0' === $_GET['instant-test-sent'] && ! empty( $recipients ) ) {
			$message = sprintf(
				esc_html__( 'Failed to send test instant notification to: %s. Please check your WordPress email settings (e.g., using an SMTP plugin).', 'wp-lead-tracker' ),
				'<strong>' . esc_html( $recipients ) . '</strong>'
			);
			echo '<div class="notice notice-error is-dismissible"><p>' . wp_kses_post( $message ) . '</p></div>';
		} else {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Cannot send test instant notification: No recipient email address is saved.', 'wp-lead-tracker' ) . '</p></div>';
		}
	}

	// Settings defaults
	$saved_settings  = get_option( 'wplt_settings', [] );
	$report_settings = wp_parse_args(
		$saved_settings,
		[
			'enabled'         => false,
			'email'           => get_option( 'admin_email' ),
			'logo_url'        => '',
			'instant_enabled' => false,
			'instant_email'   => get_option( 'admin_email' ),
		]
	);

	// Date range for counters
	$allowed_periods = [ '1', '7', '30', '90' ];
	$current_period  = isset( $_GET['period'] ) && in_array( $_GET['period'], $allowed_periods, true ) ? $_GET['period'] : '30';
	$timezone        = new DateTimeZone( 'UTC' );

	$end_date_current   = new DateTime( 'now', $timezone );
	$start_date_current = new DateTime( 'now', $timezone );
	if ( $current_period > 1 ) {
		$start_date_current->modify( '-' . ( $current_period - 1 ) . ' days' );
	}
	$start_date_current->setTime( 0, 0, 0 );
	$end_date_current->setTime( 23, 59, 59 );

	$end_date_previous   = clone $start_date_current;
	$end_date_previous->modify( '-1 second' );
	$start_date_previous = clone $end_date_previous;
	$start_date_previous->modify( '-' . ( $current_period - 1 ) . ' days' );
	$start_date_previous->setTime( 0, 0, 0 );

	$current_counts_raw = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT event_type, COUNT(id) as total FROM {$table_name} WHERE event_time BETWEEN %s AND %s GROUP BY event_type",
			$start_date_current->format( 'Y-m-d H:i:s' ),
			$end_date_current->format( 'Y-m-d H:i:s' )
		)
	);
	$previous_counts_raw = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT event_type, COUNT(id) as total FROM {$table_name} WHERE event_time BETWEEN %s AND %s GROUP BY event_type",
			$start_date_previous->format( 'Y-m-d H:i:s' ),
			$end_date_previous->format( 'Y-m-d H:i:s' )
		)
	);

	$stats = [];
	foreach ( $current_counts_raw as $row ) {
		$stats[ $row->event_type ]['current'] = (int) $row->total;
	}
	foreach ( $previous_counts_raw as $row ) {
		$stats[ $row->event_type ]['previous'] = (int) $row->total;
	}
	$all_event_types = array_keys( $stats );

	$list_table = new WPLT_Events_List_Table();
	$list_table->prepare_items();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Lead Tracker Dashboard', 'wp-lead-tracker' ); ?></h1>

		<ul class="subsubsub">
			<li><a href="?page=wp-lead-tracker&period=1" class="<?php echo '1' === $current_period ? 'current' : ''; ?>">Today</a> |</li>
			<li><a href="?page=wp-lead-tracker&period=7" class="<?php echo '7' === $current_period ? 'current' : ''; ?>">Last 7 Days</a> |</li>
			<li><a href="?page=wp-lead-tracker&period=30" class="<?php echo '30' === $current_period ? 'current' : ''; ?>">Last 30 Days</a> |</li>
			<li><a href="?page=wp-lead-tracker&period=90" class="<?php echo '90' === $current_period ? 'current' : ''; ?>">Last 90 Days</a></li>
		</ul>
		<br class="clear">

		<div id="wplt-stats" class="wplt-counter-boxes">
			<?php if ( ! empty( $all_event_types ) ) : ?>
				<?php
				foreach ( $all_event_types as $event_type ) :
					$current_val  = $stats[ $event_type ]['current'] ?? 0;
					$previous_val = $stats[ $event_type ]['previous'] ?? 0;
					$percentage   = 0;
					if ( $previous_val > 0 ) {
						$percentage = ( ( $current_val - $previous_val ) / $previous_val ) * 100;
					} elseif ( $current_val > 0 ) {
						$percentage = 100; // growth from zero
					}
					?>
					<div class="wplt-counter-box">
						<h3><?php echo esc_html( ucwords( str_replace( '_', ' ', $event_type ) ) ); ?></h3>
						<div class="count"><?php echo esc_html( number_format_i18n( $current_val ) ); ?></div>
						<?php if ( $percentage !== 0 ) : ?>
							<span class="wplt-comparison <?php echo $percentage > 0 ? 'increase' : 'decrease'; ?>">
								<?php echo ( $percentage > 0 ? '&#9650;' : '&#9660;' ) . ' ' . esc_html( round( abs( $percentage ) ) ); ?>%
							</span>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			<?php else : ?>
				<p><?php esc_html_e( 'No events have been recorded yet.', 'wp-lead-tracker' ); ?></p>
			<?php endif; ?>
		</div>

		<div id="wplt-settings" style="background: #fff; padding: 20px; margin-bottom: 20px; border-top: 1px solid #c3c4c7;">
			<form method="post" action="admin.php?page=wp-lead-tracker">
				<?php wp_nonce_field( 'wplt_save_settings_action', 'wplt_settings_nonce' ); ?>
				<h2><?php esc_html_e( 'Monthly Email Report', 'wp-lead-tracker' ); ?></h2>
				<p><?php esc_html_e( 'Enable a monthly summary report to be sent on the first of each month. You can enter multiple addresses separated by commas.', 'wp-lead-tracker' ); ?></p>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="wplt_report_enabled"><?php esc_html_e( 'Enable Report', 'wp-lead-tracker' ); ?></label></th>
						<td><input name="wplt_report_enabled" type="checkbox" id="wplt_report_enabled" <?php checked( $report_settings['enabled'] ); ?> /></td>
					</tr>
					<tr>
						<th scope="row"><label for="wplt_report_email"><?php esc_html_e( 'Recipient Email(s)', 'wp-lead-tracker' ); ?></label></th>
						<td><input name="wplt_report_email" type="text" id="wplt_report_email" value="<?php echo esc_attr( $report_settings['email'] ); ?>" class="regular-text" placeholder="email@example.com, another@example.com" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="wplt_report_logo_url"><?php esc_html_e( 'Report Logo URL', 'wp-lead-tracker' ); ?></label></th>
						<td><input name="wplt_report_logo_url" type="url" id="wplt_report_logo_url" value="<?php echo esc_attr( $report_settings['logo_url'] ); ?>" class="regular-text" placeholder="https://example.com/logo.png" /></td>
					</tr>
				</table>
				<p class="submit">
					<?php submit_button( __( 'Send Test Report', 'wp-lead-tracker' ), 'secondary', 'wplt_send_monthly_test', false ); ?>
				</p>

				<div id="wplt-instant-settings" style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
					<h2><?php esc_html_e( 'Instant Lead Notifications', 'wp-lead-tracker' ); ?></h2>
					<p><?php esc_html_e( 'Enable an email to be sent instantly whenever a new lead event is triggered.', 'wp-lead-tracker' ); ?></p>
					<table class="form-table">
						<tr>
							<th scope="row"><label for="wplt_instant_report_enabled"><?php esc_html_e( 'Enable Notifications', 'wp-lead-tracker' ); ?></label></th>
							<td><input name="wplt_instant_report_enabled" type="checkbox" id="wplt_instant_report_enabled" <?php checked( $report_settings['instant_enabled'] ); ?> /></td>
						</tr>
						<tr>
							<th scope="row"><label for="wplt_instant_report_email"><?php esc_html_e( 'Recipient Email(s)', 'wp-lead-tracker' ); ?></label></th>
							<td><input name="wplt_instant_report_email" type="text" id="wplt_instant_report_email" value="<?php echo esc_attr( $report_settings['instant_email'] ); ?>" class="regular-text" placeholder="email@example.com, another@example.com" /></td>
						</tr>
					</table>
					<p class="submit">
						<?php submit_button( __( 'Send Test Notification', 'wp-lead-tracker' ), 'secondary', 'wplt_send_instant_test', false ); ?>
					</p>
				</div>

				<p class="submit">
					<?php submit_button( __( 'Save Settings', 'wp-lead-tracker' ), 'primary', 'wplt_save_settings', false ); ?>
				</p>
			</form>
		</div>

		<p><?php esc_html_e( 'This table displays all tracked click events from your website.', 'wp-lead-tracker' ); ?></p>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( $_REQUEST['page'] ); ?>" />
			<?php $list_table->display(); ?>
		</form>
	</div>
	<?php
}

/**
 * Retrieves the total count for each event type.
 *
 * @return array
 */
function wplt_get_event_type_counts() {
	global $wpdb;
	$table_name = $wpdb->prefix . WPLT_TABLE_NAME;

	$results = $wpdb->get_results( "SELECT event_type, COUNT(id) as total FROM {$table_name} GROUP BY event_type" );
	return is_array( $results ) ? $results : [];
}

/**
 * Enqueue admin-specific stylesheets.
 */
function wplt_enqueue_admin_styles( $hook ) {
	if ( 'toplevel_page_wp-lead-tracker' !== $hook ) {
		return;
	}
	wp_enqueue_style(
		'wplt-admin-style',
		plugin_dir_url( __FILE__ ) . 'assets/css/admin-style.css',
		[],
		'1.0.0'
	);
}
add_action( 'admin_enqueue_scripts', 'wplt_enqueue_admin_styles' );

/**
 * Handles processing of dashboard actions like saving settings and test sends.
 */
function wplt_handle_dashboard_actions() {
	if ( ! isset( $_REQUEST['page'] ) || 'wp-lead-tracker' !== $_REQUEST['page'] ) {
		return;
	}

	// --- HANDLE SETTINGS SAVE ---
	if ( isset( $_POST['wplt_save_settings'] ) && isset( $_POST['wplt_settings_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wplt_settings_nonce'] ), 'wplt_save_settings_action' ) ) {
		$emails_raw       = isset( $_POST['wplt_report_email'] ) ? explode( ',', wp_unslash( $_POST['wplt_report_email'] ) ) : [];
		$sanitized_emails = [];
		foreach ( $emails_raw as $email ) {
			$trimmed_email = trim( $email );
			if ( is_email( $trimmed_email ) ) {
				$sanitized_emails[] = $trimmed_email;
			}
		}

		$instant_emails_raw       = isset( $_POST['wplt_instant_report_email'] ) ? explode( ',', wp_unslash( $_POST['wplt_instant_report_email'] ) ) : [];
		$instant_sanitized_emails = [];
		foreach ( $instant_emails_raw as $email ) {
			$trimmed_email = trim( $email );
			if ( is_email( $trimmed_email ) ) {
				$instant_sanitized_emails[] = $trimmed_email;
			}
		}

		$new_settings = [
			'enabled'         => isset( $_POST['wplt_report_enabled'] ),
			'email'           => implode( ', ', $sanitized_emails ),
			'logo_url'        => isset( $_POST['wplt_report_logo_url'] ) ? esc_url_raw( wp_unslash( $_POST['wplt_report_logo_url'] ) ) : '',
			'instant_enabled' => isset( $_POST['wplt_instant_report_enabled'] ),
			'instant_email'   => implode( ', ', $instant_sanitized_emails ),
		];

		update_option( 'wplt_settings', $new_settings );

		if ( $new_settings['enabled'] && ! empty( $new_settings['email'] ) && ! wp_next_scheduled( 'wplt_monthly_report_event' ) ) {
			$first_of_next_month = strtotime( 'first day of next month midnight' );
			wp_schedule_event( $first_of_next_month, 'monthly', 'wplt_monthly_report_event' );
		} elseif ( ( ! $new_settings['enabled'] || empty( $new_settings['email'] ) ) && wp_next_scheduled( 'wplt_monthly_report_event' ) ) {
			wp_clear_scheduled_hook( 'wplt_monthly_report_event' );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wp-lead-tracker&settings-updated=true' ) );
		exit;
	}

	// --- HANDLE MONTHLY TEST SEND ---
	if ( isset( $_POST['wplt_send_monthly_test'] ) && isset( $_POST['wplt_settings_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wplt_settings_nonce'] ), 'wplt_save_settings_action' ) ) {
		$settings_for_test = get_option( 'wplt_settings', [ 'enabled' => false, 'email' => '' ] );
		$recipients        = $settings_for_test['email'];

		if ( empty( $recipients ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-lead-tracker&test-sent=0' ) );
			exit;
		}

		$sent = wplt_send_monthly_report( true );
		$redirect_url = admin_url( 'admin.php?page=wp-lead-tracker&test-sent=' . ( $sent ? '1' : '0' ) );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	// --- HANDLE INSTANT TEST SEND (NOW FUNCTIONAL) ---
	if ( isset( $_POST['wplt_send_instant_test'] )
		&& isset( $_POST['wplt_settings_nonce'] )
		&& wp_verify_nonce( sanitize_key( $_POST['wplt_settings_nonce'] ), 'wplt_save_settings_action' ) ) {

		$settings_for_test = get_option( 'wplt_settings', [ 'instant_enabled' => false, 'instant_email' => '' ] );
		$recipients        = $settings_for_test['instant_email'];

		if ( empty( $recipients ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wp-lead-tracker&instant-test-sent=0' ) );
			exit;
		}

		$mock_data = [
			'event_type'    => 'phone_click',
			'event_label'   => '555-123-4567 (Test)',
			'traffic_type'  => 'Direct',
			'device_type'   => 'Desktop',
			'utm_source'    => 'google',
			'utm_medium'    => 'cpc',
			'utm_campaign'  => 'spring_sale',
			'utm_term'      => 'test keyword',
			'page_location' => get_site_url( null, '/test-page/' ),
		];

		$sent = wplt_send_instant_lead_notification( $mock_data );

		$redirect_url = admin_url( 'admin.php?page=wp-lead-tracker&instant-test-sent=' . ( $sent ? '1' : '0' ) );
		wp_safe_redirect( $redirect_url );
		exit;
	}
}
add_action( 'admin_init', 'wplt_handle_dashboard_actions' );

/**
 * Enqueue frontend scripts and localize data.
 */
function wplt_enqueue_scripts() {
	wp_enqueue_script(
		'wplt-frontend-tracker',
		plugin_dir_url( __FILE__ ) . 'assets/js/frontend-tracker.js',
		[],
		'1.0.0',
		true
	);

	wp_localize_script(
		'wplt-frontend-tracker',
		'wplt',
		[
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'wplt-record-event-nonce' ),
		]
	);
}
add_action( 'wp_enqueue_scripts', 'wplt_enqueue_scripts' );

/**
 * The function that generates and sends the monthly report.
 *
 * @param bool $is_test Whether this is a test run.
 * @return bool True if mail accepted, false otherwise.
 */
function wplt_send_monthly_report( $is_test = false ) {
	$saved_settings = get_option( 'wplt_settings', [] );
	$settings       = wp_parse_args(
		$saved_settings,
		[
			'enabled'  => false,
			'email'    => '',
			'logo_url' => '',
		]
	);

	if ( ( ! $is_test && ! $settings['enabled'] ) || empty( $settings['email'] ) ) {
		return false;
	}

	$recipients_raw   = explode( ',', $settings['email'] );
	$valid_recipients = [];
	foreach ( $recipients_raw as $email ) {
		$trimmed_email = trim( $email );
		if ( is_email( $trimmed_email ) ) {
			$valid_recipients[] = $trimmed_email;
		}
	}

	if ( empty( $valid_recipients ) ) {
		return false;
	}

	global $wpdb;
	$table_name = $wpdb->prefix . WPLT_TABLE_NAME;
	$logo_url   = $settings['logo_url'];
	$timezone   = new DateTimeZone( 'UTC' );

	if ( $is_test ) {
		$end_date   = new DateTime( 'now', $timezone );
		$start_date = new DateTime( 'now', $timezone );
		$start_date->modify( '-29 days' )->setTime( 0, 0, 0 );
		$month_name = 'Test Report (Last 30 Days)';
	} else {
		$end_date   = new DateTime( 'first day of this month', $timezone );
		$end_date->modify( '-1 second' );
		$start_date = new DateTime( 'first day of last month', $timezone );
		$start_date->setTime( 0, 0, 0 );
		$month_name = $start_date->format( 'F Y' );
	}

	$site_title = get_bloginfo( 'name' );
	$subject    = sprintf( 'Your Monthly Lead Report for %s from %s', $month_name, $site_title );

	// Build data sets
	$start_date_previous = clone $start_date;
	$start_date_previous->modify( '-1 month' );
	$end_date_previous = clone $end_date;
	$end_date_previous->modify( '-1 month' );

	$current_counts_raw = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT event_type, COUNT(id) as total FROM {$table_name} WHERE event_time BETWEEN %s AND %s GROUP BY event_type",
			$start_date->format( 'Y-m-d H:i:s' ),
			$end_date->format( 'Y-m-d H:i:s' )
		)
	);
	$previous_counts_raw = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT event_type, COUNT(id) as total FROM {$table_name} WHERE event_time BETWEEN %s AND %s GROUP BY event_type",
			$start_date_previous->format( 'Y-m-d H:i:s' ),
			$end_date_previous->format( 'Y-m-d H:i:s' )
		)
	);

	$top_pages = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT submitting_url, MAX(page_location) as page_location, COUNT(id) as total FROM {$table_name} WHERE event_time BETWEEN %s AND %s GROUP BY submitting_url ORDER BY total DESC LIMIT 3",
			$start_date->format( 'Y-m-d H:i:s' ),
			$end_date->format( 'Y-m-d H:i:s' )
		)
	);

	$top_sources = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT utm_source, COUNT(id) as total FROM {$table_name} WHERE event_time BETWEEN %s AND %s AND utm_source != '' GROUP BY utm_source ORDER BY total DESC LIMIT 3",
			$start_date->format( 'Y-m-d H:i:s' ),
			$end_date->format( 'Y-m-d H:i:s' )
		)
	);

	$twelve_months_ago = clone $start_date;
	$twelve_months_ago->modify( '-11 months' );
	$monthly_trend_raw = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT YEAR(event_time) as event_year, MONTH(event_time) as event_month, COUNT(id) as total FROM {$table_name} WHERE event_time BETWEEN %s AND %s GROUP BY event_year, event_month ORDER BY event_year, event_month",
			$twelve_months_ago->format( 'Y-m-d H:i:s' ),
			$end_date->format( 'Y-m-d H:i:s' )
		)
	);

	$monthly_trend   = [];
	$max_trend_leads = 0;
	for ( $i = 0; $i < 12; $i++ ) {
		$loop_date = clone $end_date;
		$loop_date->modify( "-$i months" );
		$month_key = $loop_date->format( 'M \'y' );
		$year_num  = $loop_date->format( 'Y' );
		$month_num = $loop_date->format( 'n' );

		$found_leads = 0;
		foreach ( $monthly_trend_raw as $row ) {
			if ( (int) $row->event_year === (int) $year_num && (int) $row->event_month === (int) $month_num ) {
				$found_leads = (int) $row->total;
				break;
			}
		}
		$monthly_trend[ $month_key ] = $found_leads;
		if ( $found_leads > $max_trend_leads ) {
			$max_trend_leads = $found_leads;
		}
	}
	$monthly_trend = array_reverse( $monthly_trend, true );

	$months_with_data = 0;
	foreach ( $monthly_trend as $leads ) {
		if ( $leads > 0 ) {
			$months_with_data++;
		}
	}
	$show_trend_chart = ( $months_with_data > 1 );

	$stats                = [];
	$total_leads = 0;
	$previous_total_leads = 0;
	foreach ( $current_counts_raw as $row ) {
		$stats[ $row->event_type ]['current'] = (int) $row->total;
		$total_leads                        += (int) $row->total;
	}
	foreach ( $previous_counts_raw as $row ) {
		$stats[ $row->event_type ]['previous'] = (int) $row->total;
		$previous_total_leads               += (int) $row->total;
	}
	$all_event_types = array_unique( array_merge( array_keys( $stats ), array_column( $previous_counts_raw, 'event_type' ) ) );

	// Build email body
	ob_start();
	$event_icons = [
		'phone_click' => '☎️',
		'email_click' => '📩',
		'sms_click'   => '📱',
	];
	?>
	<table width="100%" border="0" cellpadding="0" cellspacing="0" style="background-color:#f2f4f6; font-family: sans-serif; padding: 20px 0;">
		<tr>
			<td align="center">
				<table width="600" border="0" cellpadding="20" cellspacing="0" style="background-color:#ffffff; border-radius: 8px; text-align: left;">
					<?php if ( ! empty( $logo_url ) ) : ?>
						<tr><td align="center" style="padding-bottom: 0;"><img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $site_title ); ?> Logo" style="max-width: 150px; margin-bottom: 20px;"></td></tr>
					<?php endif; ?>
					<tr><td>
						<h2 style="font-size: 24px; margin: 0; text-align: center;">🎉 Your website generated <strong><?php echo esc_html( number_format_i18n( $total_leads ) ); ?></strong> leads this past month!</h2>
					</td></tr>
					<tr><td>
						<p style="font-size: 16px;">Here is a breakdown of the lead events recorded on <?php echo esc_html( $site_title ); ?> for <?php echo esc_html( $month_name ); ?>:</p>
						<?php if ( ! empty( $all_event_types ) ) : ?>
							<ul style="font-size: 16px; list-style-type: none; padding: 0;">
								<?php
								foreach ( $all_event_types as $event_type ) :
									$current_val  = $stats[ $event_type ]['current'] ?? 0;
									$previous_val = $stats[ $event_type ]['previous'] ?? 0;
									$percentage   = 0;
									if ( $previous_val > 0 ) {
										$percentage = ( ( $current_val - $previous_val ) / $previous_val ) * 100;
									} elseif ( $current_val > 0 ) {
										$percentage = 100;
									}
									$icon = $event_icons[ $event_type ] ?? '➡️';
									?>
									<li style="margin-bottom: 15px;">
										<?php echo esc_html( $icon ); ?> <strong><?php echo esc_html( ucwords( str_replace( '_', ' ', $event_type ) ) ); ?>:</strong> <?php echo esc_html( number_format_i18n( $current_val ) ); ?>
										<?php if ( $percentage !== 0 ) : ?>
											<span style="color: <?php echo $percentage > 0 ? '#00a32a' : '#d63638'; ?>;">(<?php echo $percentage > 0 ? '+' : ''; ?><?php echo esc_html( round( $percentage ) ); ?>% vs. last month)</span>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php else : ?>
							<p>No events were recorded during this period.</p>
						<?php endif; ?>
					</td></tr>

					<?php if ( ! empty( $top_sources ) ) : ?>
						<tr><td><h2 style="font-size: 20px; margin-bottom: 10px;">🔝 Top Traffic Sources</h2>
						<ul style="font-size: 16px; padding-left: 20px; margin: 0;">
							<?php foreach ( $top_sources as $source ) : ?>
								<li><?php echo esc_html( $source->utm_source ); ?> (<?php echo esc_html( number_format_i18n( $source->total ) ); ?> leads)</li>
							<?php endforeach; ?>
						</ul></td></tr>
					<?php endif; ?>

					<?php if ( ! empty( $top_pages ) ) : ?>
						<tr><td><h2 style="font-size: 20px; margin-bottom: 10px;">📄 Top Pages Generating Leads</h2>
						<ul style="font-size: 16px; padding-left: 20px; margin: 0;">
							<?php foreach ( $top_pages as $page ) : ?>
								<li><a href="<?php echo esc_url( $page->page_location ); ?>" style="color: #0073aa; text-decoration: none;"><?php echo esc_html( $page->submitting_url ); ?></a> (<?php echo esc_html( number_format_i18n( $page->total ) ); ?> leads)</li>
							<?php endforeach; ?>
						</ul></td></tr>
					<?php endif; ?>

					<?php if ( $show_trend_chart ) : ?>
						<tr><td><h2 style="font-size: 20px; margin-bottom: 10px;">📊 Performance Over the Last 12 Months</h2>
						<table style="width: 100%; border-collapse: collapse; font-size: 12px; margin-top: 15px;">
							<?php
							$is_first_bar = true;
							foreach ( $monthly_trend as $month => $leads ) :
								$bar_width = ( $max_trend_leads > 0 ) ? ( $leads / $max_trend_leads ) * 100 : 0;
								$bar_color = $is_first_bar ? '#005a9c' : '#72aee6'; // Highlight the most recent month
								?>
								<tr>
									<td style="padding: 4px; width: 60px;"><?php echo esc_html( $month ); ?></td>
									<td style="padding: 4px; width: 40px; text-align: right; font-weight: bold;"><?php echo esc_html( number_format_i18n( $leads ) ); ?></td>
									<td style="padding: 4px;">
										<div style="width: <?php echo esc_attr( max( 1, $bar_width ) ); ?>%; background-color: <?php echo esc_attr( $bar_color ); ?>; height: 20px; border-radius: 3px;">&nbsp;</div>
									</td>
								</tr>
								<?php
								$is_first_bar = false;
							endforeach;
							?>
						</table></td></tr>
					<?php endif; ?>
					<tr><td style="text-align: center; padding-top: 30px; border-top: 1px solid #eeeeee; margin-top: 20px;">
						<?php if ( $total_leads >= $previous_total_leads ) : ?>
							<p style="font-size: 16px; color: #50575e;">Great work! Let’s keep building on this momentum.</p>
						<?php else : ?>
							<p style="font-size: 16px; color: #50575e;">Let's focus on improving these numbers for next month's report.</p>
						<?php endif; ?>
					</td></tr>
				</table>
			</td>
		</tr>
	</table>
	<?php
	$body = ob_get_clean();

	$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
	return (bool) wp_mail( $valid_recipients, $subject, $body, $headers );
}
add_action( 'wplt_monthly_report_event', 'wplt_send_monthly_report' );

/**
 * Sends an instant notification for a new lead.
 *
 * @param array $lead_data The data for the lead that was just recorded.
 * @return bool True if mail accepted for delivery, false otherwise.
 */
function wplt_send_instant_lead_notification( $lead_data ) {
	$saved_settings = get_option( 'wplt_settings', [] );
	$settings       = wp_parse_args(
		$saved_settings,
		[
			'instant_enabled' => false,
			'instant_email'   => '',
		]
	);

	// Check if enabled and if there are recipients.
	if ( ! $settings['instant_enabled'] || empty( $settings['instant_email'] ) ) {
		return false;
	}

	$recipients_raw   = explode( ',', $settings['instant_email'] );
	$valid_recipients = [];
	foreach ( $recipients_raw as $email ) {
		$trimmed_email = trim( $email );
		if ( is_email( $trimmed_email ) ) {
			$valid_recipients[] = $trimmed_email;
		}
	}

	if ( empty( $valid_recipients ) ) {
		return false;
	}

	$site_title           = get_bloginfo( 'name' );
	$event_type_formatted = isset( $lead_data['event_type'] ) ? ucwords( str_replace( '_', ' ', $lead_data['event_type'] ) ) : 'Lead';
	$subject              = sprintf( 'New Lead on %s: %s', $site_title, $event_type_formatted );

	// Build email body.
	ob_start();
	?>
	<h2 style="font-family: sans-serif;">New Lead Notification</h2>
	<p style="font-family: sans-serif;">A new lead event was just triggered on <?php echo esc_html( $site_title ); ?>.</p>
	<table style="font-family: sans-serif; border-collapse: collapse; width: 100%;">
		<?php
		$data_map = [
			'event_type'    => 'Event Type',
			'event_label'   => 'Event Label',
			'traffic_type'  => 'Traffic Type',
			'device_type'   => 'Device Type',
			'utm_source'    => 'UTM Source',
			'utm_medium'    => 'UTM Medium',
			'utm_campaign'  => 'UTM Campaign',
			'utm_term'      => 'UTM Term',
			'page_location' => 'Page URL',
		];

		foreach ( $data_map as $key => $label ) {
			if ( ! empty( $lead_data[ $key ] ) ) {
				$value = ( 'event_type' === $key ) ? ucwords( str_replace( '_', ' ', $lead_data[ $key ] ) ) : $lead_data[ $key ];
				?>
				<tr style="border-bottom: 1px solid #eeeeee;">
					<td style="padding: 8px; font-weight: bold;"><?php echo esc_html( $label ); ?>:</td>
					<td style="padding: 8px;"><?php echo esc_html( $value ); ?></td>
				</tr>
				<?php
			}
		}
		?>
	</table>
	<?php
	$body = ob_get_clean();

	$headers = [ 'Content-Type: text/html; charset=UTF-8' ];

	return (bool) wp_mail( $valid_recipients, $subject, $body, $headers );
}

/**
 * Handle the AJAX request to record an event.
 */
function wplt_record_event_handler() {
	// Verify nonce for security.
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'wplt-record-event-nonce' ) ) {
		wp_send_json_error( 'Nonce verification failed.', 403 );
	}

	global $wpdb;
	$table_name = $wpdb->prefix . WPLT_TABLE_NAME;

	// Sanitize incoming data.
	$data = [
		'event_time'     => current_time( 'mysql', 1 ), // GMT time.
		'event_type'     => isset( $_POST['eventType'] ) ? sanitize_text_field( wp_unslash( $_POST['eventType'] ) ) : '',
		'event_label'    => isset( $_POST['eventLabel'] ) ? sanitize_text_field( wp_unslash( $_POST['eventLabel'] ) ) : '',
		'traffic_type'   => isset( $_POST['trafficType'] ) ? sanitize_text_field( wp_unslash( $_POST['trafficType'] ) ) : '',
		'device_type'    => isset( $_POST['deviceType'] ) ? sanitize_text_field( wp_unslash( $_POST['deviceType'] ) ) : '',
		'utm_source'     => isset( $_POST['utm_source'] ) ? sanitize_text_field( wp_unslash( $_POST['utm_source'] ) ) : '',
		'utm_medium'     => isset( $_POST['utm_medium'] ) ? sanitize_text_field( wp_unslash( $_POST['utm_medium'] ) ) : '',
		'utm_campaign'   => isset( $_POST['utm_campaign'] ) ? sanitize_text_field( wp_unslash( $_POST['utm_campaign'] ) ) : '',
		'utm_term'       => isset( $_POST['utm_term'] ) ? sanitize_text_field( wp_unslash( $_POST['utm_term'] ) ) : '',
		'ad_id'          => isset( $_POST['ad_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ad_id'] ) ) : '',
		'entry_url'      => isset( $_POST['entryUrl'] ) ? esc_url_raw( wp_unslash( $_POST['entryUrl'] ) ) : '',
		'submitting_url' => isset( $_POST['submittingUrl'] ) ? esc_url_raw( wp_unslash( $_POST['submittingUrl'] ) ) : '',
		'page_location'  => isset( $_POST['pageLocation'] ) ? esc_url_raw( wp_unslash( $_POST['pageLocation'] ) ) : '',
	];

	$result = $wpdb->insert( $table_name, $data );

	if ( false === $result ) {
		wp_send_json_error( 'Failed to save event to the database.' );
	} else {
		// Trigger instant notification (returns bool, but we don't need it here).
		wplt_send_instant_lead_notification( $data );
		wp_send_json_success( 'Event recorded.' );
	}
}
add_action( 'wp_ajax_wplt_record_event', 'wplt_record_event_handler' );
add_action( 'wp_ajax_nopriv_wplt_record_event', 'wplt_record_event_handler' );

<?php
/**
 * Plugin Name: Ontrack Migrator
 * Description: One-time database key migration — renames kcfa_/klac_ prefixed option, user-meta, and post-meta keys to ontrack_ prefix. Safe to run multiple times. Delete after use.
 * Version:     1.0.0
 * Author:      KCFA
 * @package     OntrackMigrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Migration definitions
// ---------------------------------------------------------------------------

/**
 * Returns every migration as an array of:
 *   [ type, old_key, new_key, label ]
 *
 * Types:
 *   option_rename — renames the option_name row in wp_options
 *   option_copy   — copies value to new key, keeps old key (for theme_mods)
 *   user_meta     — renames meta_key rows in wp_usermeta
 *   post_meta     — renames meta_key rows in wp_postmeta
 */
function ontrack_migrator_definitions() {
	return array(

		// ── Options ──────────────────────────────────────────────────────────
		array( 'option_rename', 'klac_ontrack_integrations',     'ontrack_integrations',         'Box / Bunny / Vimeo API credentials' ),
		array( 'option_rename', 'kcfa_membership_options',       'ontrack_options',              'Plugin settings' ),
		array( 'option_rename', 'kcfa_membership_plugin_version','ontrack_plugin_version',       'Plugin version tracker' ),
		array( 'option_rename', 'kcfa_jwt_secret',               'ontrack_jwt_secret',           'JWT signing secret (existing tokens stay valid — value is unchanged)' ),
		// theme_mods: copy only — WordPress must find the old key while the old theme folder is still active
		array( 'option_copy',   'theme_mods_klac',               'theme_mods_ontrack',           'Customizer / theme-mods (copied; original kept until theme folder is switched)' ),

		// ── User meta ─────────────────────────────────────────────────────────
		array( 'user_meta', 'kcfa_date_of_birth',          'ontrack_date_of_birth',         'Member date of birth' ),
		array( 'user_meta', 'kcfa_gender',                 'ontrack_gender',                'Member gender' ),
		array( 'user_meta', 'kcfa_city',                   'ontrack_city',                  'Member city' ),
		array( 'user_meta', 'kcfa_country',                'ontrack_country',               'Member country' ),
		array( 'user_meta', 'kcfa_is_member',              'ontrack_is_member',             'Full-member status flag' ),
		array( 'user_meta', 'kcfa_mismatch_ack',           'ontrack_mismatch_ack',          'Acknowledged demographic mismatches' ),
		array( 'user_meta', 'kcfa_refresh_token',          'ontrack_refresh_token',         'JWT refresh tokens (mobile logins preserved)' ),
		array( 'user_meta', 'kcfa_refresh_token_expires',  'ontrack_refresh_token_expires', 'JWT refresh token expiry timestamps' ),

		// ── Post meta ─────────────────────────────────────────────────────────
		array( 'post_meta', '_klac_box_folder_id',          '_ontrack_box_folder_id',          'Box.com folder ID' ),
		array( 'post_meta', '_klac_video_provider',         '_ontrack_video_provider',         'Video provider (bunny / vimeo)' ),
		array( 'post_meta', '_klac_bunny_collection_id',    '_ontrack_bunny_collection_id',    'Bunny Stream collection GUID' ),
		array( 'post_meta', '_klac_vimeo_showcase_id',      '_ontrack_vimeo_showcase_id',      'Vimeo showcase ID' ),
		array( 'post_meta', '_klac_tab_collection_type',    '_ontrack_tab_collection_type',    'Mobile tab — collection type' ),
		array( 'post_meta', '_klac_tab_post_category_id',   '_ontrack_tab_post_category_id',   'Mobile tab — post category ID' ),
		array( 'post_meta', '_klac_tab_post_tag_id',        '_ontrack_tab_post_tag_id',        'Mobile tab — post tag ID' ),
		array( 'post_meta', '_klac_tab_post_limit',         '_ontrack_tab_post_limit',         'Mobile tab — post limit' ),
		array( 'post_meta', '_klac_allowed_group_ids',      '_ontrack_allowed_group_ids',      'Post access — allowed group IDs' ),
		array( 'post_meta', '_klac_pinned',                 '_ontrack_pinned',                 'Post pinned flag (mobile feed)' ),
		array( 'post_meta', '_klac_members_show_animation', '_ontrack_members_show_animation', 'Members area — show animation' ),
		array( 'post_meta', '_klac_members_animation_id',   '_ontrack_members_animation_id',   'Members area — animation attachment ID' ),
		array( 'post_meta', '_klac_members_show_countdown', '_ontrack_members_show_countdown', 'Members area — show countdown' ),
		array( 'post_meta', '_klac_members_countdown_mode', '_ontrack_members_countdown_mode', 'Members area — countdown mode' ),
		array( 'post_meta', '_klac_members_countdown_day',  '_ontrack_members_countdown_day',  'Members area — countdown day' ),
		array( 'post_meta', '_klac_members_countdown_time', '_ontrack_members_countdown_time', 'Members area — countdown time' ),
		array( 'post_meta', '_klac_members_countdown_rest', '_ontrack_members_countdown_rest', 'Members area — countdown rest window' ),
		array( 'post_meta', '_klac_members_countdown_date', '_ontrack_members_countdown_date', 'Members area — countdown target date' ),
		array( 'post_meta', 'kcfa_catalogue_access',        'ontrack_catalogue_access',        'Collection access level' ),
		array( 'post_meta', 'kcfa_group_demographics',      'ontrack_group_demographics',      'Group demographic rules' ),
	);
}

// ---------------------------------------------------------------------------
// Core logic — preview (count) and run (execute)
// ---------------------------------------------------------------------------

const ONTRACK_MIGRATOR_DONE_KEY = 'ontrack_migrator_v1_done';

/**
 * Returns an array of preview rows — counts of rows that would be affected.
 */
function ontrack_migrator_preview() {
	global $wpdb;
	$rows = array();

	foreach ( ontrack_migrator_definitions() as $def ) {
		list( $type, $old, $new, $label ) = $def;

		$old_count = 0;
		$new_exists = false;

		switch ( $type ) {
			case 'option_rename':
			case 'option_copy':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$old_count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $old ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$new_exists = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $new ) );
				break;

			case 'user_meta':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$old_count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", $old ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$new_exists = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", $new ) );
				break;

			case 'post_meta':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$old_count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", $old ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$new_exists = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", $new ) );
				break;
		}

		$rows[] = array(
			'type'       => $type,
			'old'        => $old,
			'new'        => $new,
			'label'      => $label,
			'old_count'  => $old_count,
			'new_exists' => $new_exists,
		);
	}

	return $rows;
}

/**
 * Execute all migrations. Returns an array of result rows.
 * Safe to call multiple times — idempotent.
 */
function ontrack_migrator_run() {
	global $wpdb;
	$results = array();

	foreach ( ontrack_migrator_definitions() as $def ) {
		list( $type, $old, $new, $label ) = $def;
		$rows_affected = 0;
		$note          = '';

		switch ( $type ) {

			case 'option_rename':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$new_exists = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $new ) );
				if ( $new_exists ) {
					// New key already exists — just clean up the old one if still around.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->delete( $wpdb->options, array( 'option_name' => $old ) );
					$note = 'new key already existed; old key removed';
				} else {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$rows_affected = (int) $wpdb->update(
						$wpdb->options,
						array( 'option_name' => $new ),
						array( 'option_name' => $old )
					);
					$note = $rows_affected ? 'renamed' : 'key not found — nothing to do';
				}
				break;

			case 'option_copy':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$new_exists = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $new ) );
				if ( $new_exists ) {
					$note = 'new key already exists — skipped';
				} else {
					$value = get_option( $old );
					if ( false !== $value ) {
						update_option( $new, $value, false );
						$rows_affected = 1;
						$note = 'copied (original preserved)';
					} else {
						$note = 'source key not found — nothing to do';
					}
				}
				break;

			case 'user_meta':
				// Delete any rows where the new key already exists for a user
				// (handles partial previous runs) before bulk-renaming old key.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( $wpdb->prepare(
					"DELETE u_old FROM {$wpdb->usermeta} u_old
					 INNER JOIN {$wpdb->usermeta} u_new
					   ON u_old.user_id = u_new.user_id
					  AND u_old.meta_key = %s
					  AND u_new.meta_key = %s",
					$old, $new
				) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$rows_affected = (int) $wpdb->query( $wpdb->prepare(
					"UPDATE {$wpdb->usermeta} SET meta_key = %s WHERE meta_key = %s",
					$new, $old
				) );
				$note = $rows_affected ? 'renamed' : 'key not found — nothing to do';
				break;

			case 'post_meta':
				// Same pattern — remove already-migrated duplicates first.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( $wpdb->prepare(
					"DELETE p_old FROM {$wpdb->postmeta} p_old
					 INNER JOIN {$wpdb->postmeta} p_new
					   ON p_old.post_id = p_new.post_id
					  AND p_old.meta_key = %s
					  AND p_new.meta_key = %s",
					$old, $new
				) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$rows_affected = (int) $wpdb->query( $wpdb->prepare(
					"UPDATE {$wpdb->postmeta} SET meta_key = %s WHERE meta_key = %s",
					$new, $old
				) );
				$note = $rows_affected ? 'renamed' : 'key not found — nothing to do';
				break;
		}

		$results[] = array(
			'type'          => $type,
			'old'           => $old,
			'new'           => $new,
			'label'         => $label,
			'rows_affected' => $rows_affected,
			'note'          => $note,
		);
	}

	update_option(
		ONTRACK_MIGRATOR_DONE_KEY,
		array(
			'completed_at' => current_time( 'mysql' ),
			'results'      => $results,
		),
		false
	);

	return $results;
}

// ---------------------------------------------------------------------------
// Admin UI
// ---------------------------------------------------------------------------

add_action( 'admin_menu', function () {
	add_management_page(
		'Ontrack Migrator',
		'Ontrack Migrator',
		'manage_options',
		'ontrack-migrator',
		'ontrack_migrator_admin_page'
	);
} );

function ontrack_migrator_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$done        = get_option( ONTRACK_MIGRATOR_DONE_KEY );
	$run_results = null;

	if ( isset( $_POST['ontrack_migrator_run'] ) &&
		 check_admin_referer( 'ontrack_migrator_run' ) &&
		 ! empty( $_POST['ontrack_migrator_confirm'] ) ) {
		$run_results = ontrack_migrator_run();
		$done        = get_option( ONTRACK_MIGRATOR_DONE_KEY );
	}

	$preview       = ontrack_migrator_preview();
	$total_pending = array_sum( array_column( $preview, 'old_count' ) );
	?>
	<div class="wrap">
		<h1>Ontrack Migrator</h1>
		<p>Renames database keys from <code>kcfa_</code> / <code>klac_</code> prefix to <code>ontrack_</code> prefix.
		   Safe to run multiple times. Delete this plugin when done.</p>

		<?php if ( $done && ! $run_results ) : ?>
		<div class="notice notice-success inline"><p>
			<strong>Migration already completed</strong> on <?php echo esc_html( $done['completed_at'] ); ?>.
			You can deactivate and delete this plugin.
		</p></div>
		<?php endif; ?>

		<?php if ( $run_results ) : ?>
		<div class="notice notice-success inline"><p><strong>Migration complete.</strong></p></div>
		<h2>Results</h2>
		<table class="widefat striped" style="max-width:900px">
			<thead>
				<tr>
					<th>Description</th>
					<th>Old key</th>
					<th>New key</th>
					<th>Rows</th>
					<th>Note</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $run_results as $r ) : ?>
				<tr>
					<td><?php echo esc_html( $r['label'] ); ?></td>
					<td><code><?php echo esc_html( $r['old'] ); ?></code></td>
					<td><code><?php echo esc_html( $r['new'] ); ?></code></td>
					<td><?php echo (int) $r['rows_affected']; ?></td>
					<td style="color:#555"><?php echo esc_html( $r['note'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<div style="margin-top:1.5em; padding:1em; background:#e8f5e9; border:1px solid #a5d6a7; border-radius:4px; max-width:900px">
			<strong>Next steps:</strong>
			<ol style="margin:.5em 0 0 1.5em">
				<li>Deactivate and delete this plugin.</li>
				<li>Flush permalinks: <strong>Settings → Permalinks → Save Changes</strong>.</li>
				<li>Disable maintenance mode.</li>
			</ol>
		</div>

		<?php else : ?>

		<h2>Preview — <?php echo (int) $total_pending; ?> rows pending migration</h2>
		<table class="widefat striped" style="max-width:900px">
			<thead>
				<tr>
					<th>Description</th>
					<th>Old key</th>
					<th>New key</th>
					<th>Type</th>
					<th>Rows</th>
					<th>Status</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $preview as $p ) :
				if ( $p['old_count'] > 0 ) {
					$status = '<span style="color:#e65100;font-weight:600">Pending</span>';
				} elseif ( $p['new_exists'] ) {
					$status = '<span style="color:#2e7d32">&#10003; Done</span>';
				} else {
					$status = '<span style="color:#777">No data</span>';
				}
			?>
				<tr>
					<td><?php echo esc_html( $p['label'] ); ?></td>
					<td><code><?php echo esc_html( $p['old'] ); ?></code></td>
					<td><code><?php echo esc_html( $p['new'] ); ?></code></td>
					<td><small><?php echo esc_html( $p['type'] ); ?></small></td>
					<td><?php echo (int) $p['old_count']; ?></td>
					<td><?php echo $status; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $total_pending > 0 ) : ?>
		<div style="margin-top:2em; padding:1.5em; background:#fff8e1; border:1px solid #ffe082; border-radius:4px; max-width:900px">
			<h3 style="margin-top:0">&#9888; Before you run</h3>
			<ol>
				<li><strong>Enable maintenance mode</strong> so visitors see a maintenance page during the switch.</li>
				<li><strong>Deploy the updated Ontrack plugin</strong> (the version that reads <code>ontrack_</code> key names) to the server <em>before</em> clicking Run — but keep the old plugin folder active until this migration completes.</li>
				<li>Run the migration below.</li>
				<li>Switch the active plugin folder and theme folder to the new names.</li>
				<li>Flush permalinks and disable maintenance mode.</li>
			</ol>
			<form method="post">
				<?php wp_nonce_field( 'ontrack_migrator_run' ); ?>
				<label style="display:flex;align-items:flex-start;gap:.5em;cursor:pointer">
					<input type="checkbox" name="ontrack_migrator_confirm" value="1" required style="margin-top:3px" />
					<span>I have read the steps above, the site is in maintenance mode, and the updated Ontrack plugin is deployed.</span>
				</label>
				<br>
				<input type="submit" name="ontrack_migrator_run"
					class="button button-primary button-hero"
					value="Run Migration Now" />
			</form>
		</div>
		<?php else : ?>
		<p style="color:#2e7d32;font-weight:600">&#10003; All rows are already on new key names. Nothing to do.</p>
		<?php endif; ?>

		<?php endif; ?>
	</div>
	<?php
}

<?php
/** WordPress integration assertions for PR2 trust boundaries. */

use Longevity\Core\Approval_Service;
use Longevity\Core\Meta_Authorization;
use Longevity\Core\Rest_API;
use Longevity\Core\Runtime_Config;
use Longevity\Core\System_Readiness;

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$writer = get_user_by( 'login', 'pr2_writer' );
if ( ! $writer ) {
	$user_id = wp_create_user( 'pr2_writer', wp_generate_password( 32 ), 'pr2-writer@example.test' );
	$writer  = get_user_by( 'id', $user_id );
	$writer->set_role( 'lel_writer' );
}
$post_id = wp_insert_post(
	array(
		'post_type'    => 'post',
		'post_status'  => 'draft',
		'post_title'   => 'PR2 authorization fixture',
		'post_content' => 'Fixture content.',
		'post_author'  => $writer->ID,
	)
);
$assert( Meta_Authorization::can_write( 'content_summary', $post_id, $writer->ID, 'classic' ), 'Writer lost descriptive-field access.' );
$assert( ! Meta_Authorization::can_write( 'medical_review_required', $post_id, $writer->ID, 'classic' ), 'Writer can lower medical-review requirement.' );
$assert( ! Meta_Authorization::can_write( 'testing_required', $post_id, $writer->ID, 'rest' ), 'Writer can lower testing requirement through REST policy.' );
$assert( ! Meta_Authorization::can_write( 'unknown_governance_field', $post_id, $writer->ID, 'rest' ), 'Unknown metadata policy did not deny.' );

Meta_Authorization::enter_trusted_scope();
try {
	update_post_meta( $post_id, 'medical_review_required', true );
} finally {
	Meta_Authorization::exit_trusted_scope();
}
wp_set_current_user( $writer->ID );
$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
$request->set_param( 'meta', array( 'medical_review_required' => false ) );
rest_do_request( $request );
$assert( true === (bool) get_post_meta( $post_id, 'medical_review_required', true ), 'Anonymous/raw REST contract changed protected metadata.' );

$editor = get_user_by( 'login', 'pr2_editor' );
if ( ! $editor ) {
	$user_id = wp_create_user( 'pr2_editor', wp_generate_password( 32 ), 'pr2-editor@example.test' );
	$editor  = get_user_by( 'id', $user_id );
	$editor->set_role( 'lel_managing_editor' );
}
wp_set_current_user( $editor->ID );
update_post_meta( $post_id, 'content_summary', 'Synthetic summary for approval integration.' );
update_post_meta( $post_id, 'content_limitations', 'Synthetic limitations for approval integration.' );
update_post_meta( $post_id, 'commercial_relationship', 'none' );
update_post_meta( $post_id, 'next_content_review_date', gmdate( 'Y-m-d', strtotime( '+180 days' ) ) );
$assert( ! Meta_Authorization::can_write( 'editorial_approval_status', $post_id, $editor->ID, 'rest' ), 'REST can forge final editorial state.' );
$approval = Approval_Service::approve( $post_id, 'editorial', $editor->ID );
$assert( is_array( $approval ), 'Editorial approval snapshot could not be created.' );
$assert( Approval_Service::is_current( $post_id, 'editorial' ), 'New editorial snapshot is not current.' );
wp_update_post( array( 'ID' => $post_id, 'post_content' => 'Materially changed fixture content.' ) );
$assert( ! Approval_Service::is_current( $post_id, 'editorial' ), 'Content edit did not invalidate editorial approval.' );
$assert( 'stale' === get_post_meta( $post_id, 'editorial_approval_status', true ), 'Invalidation did not project stale state.' );

$health = Rest_API::health()->get_data();
$assert( array( 'status' => 'ok' ) === $health, 'Public health response exposes more than liveness.' );
$assert( Runtime_Config::scoring_model_status()['valid'], 'Packaged scoring model is invalid.' );
$readiness_json = wp_json_encode( System_Readiness::report() );
$assert( false === str_contains( $readiness_json, ABSPATH ), 'Readiness report leaked a filesystem path.' );
$assert( str_contains( $readiness_json, 'unknown_external' ), 'External readiness evidence was fabricated as known.' );

wp_delete_post( $post_id, true );
if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}
	exit( 1 );
}
echo "PR2 authorization, approval invalidation, health, runtime-config, and readiness contracts passed.\n";

<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Headless clients（Astro 等）へ、指定ページ・配置に対して選定済みの
 * コンテキスト広告を返す REST API。
 *
 * 広告の選定条件は既存の cam_get_context_ad_for_post_and_placement()
 * に委譲し、広告管理データの全件は公開しない。
 */
function cam_register_context_ad_rest_routes() {
	register_rest_route(
		'ca-manager/v1',
		'/context-ad',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'cam_rest_get_context_ad',
			'permission_callback' => '__return_true',
			'args'                => array(
				'post_id'   => array(
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
				'placement' => array(
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
				),
			),
		)
	);

	register_rest_route(
		'ca-manager/v1',
		'/context-ad/click',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'cam_rest_context_ad_click',
			'permission_callback' => '__return_true',
		)
	);

	register_rest_route(
		'ca-manager/v1',
		'/context-ad/event',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'cam_rest_log_context_ad_event',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'cam_register_context_ad_rest_routes' );

/**
 * @return WP_REST_Response|WP_Error
 */
function cam_rest_get_context_ad( WP_REST_Request $request ) {
	$post_id   = absint( $request->get_param( 'post_id' ) );
	$placement = sanitize_key( (string) $request->get_param( 'placement' ) );
	$post      = get_post( $post_id );

	if ( ! $post || 'publish' !== get_post_status( $post ) ) {
		return new WP_Error(
			'cam_context_ad_post_not_found',
			'Published post or page was not found.',
			array( 'status' => 404 )
		);
	}

	if ( ! in_array( $placement, array( 'top', 'middle', 'bottom' ), true ) ) {
		return new WP_Error(
			'cam_context_ad_invalid_placement',
			'Invalid placement.',
			array( 'status' => 400 )
		);
	}

	$ad = cam_get_context_ad_for_post_and_placement( $post_id, $placement );

	if ( ! is_array( $ad ) || empty( $ad ) ) {
		return rest_ensure_response(
			array(
				'postId'    => $post_id,
				'placement' => $placement,
				'ad'        => null,
			)
		);
	}

	$ad_id = isset( $ad['id'] ) ? (string) $ad['id'] : '';

	return rest_ensure_response(
		array(
			'postId'    => $post_id,
			'placement' => $placement,
			'ad'        => array(
				'id'          => $ad_id,
				'elementId'   => 'cam-context-ad-' . $placement . '-' . $post_id,
				'advertiser'  => isset( $ad['advertiser'] ) ? (string) $ad['advertiser'] : '',
				'headline'    => isset( $ad['headline'] ) ? (string) $ad['headline'] : '',
				'image'       => isset( $ad['image'] ) ? (string) $ad['image'] : '',
				'destination' => isset( $ad['destination'] ) ? (string) $ad['destination'] : '',
				'clickUrl'    => '' !== $ad_id
					? add_query_arg(
						array(
							'ad_id'     => $ad_id,
							'placement' => $placement,
							'post_id'   => $post_id,
							'genre'     => isset( $ad['genre'] ) ? (string) $ad['genre'] : '',
						),
						rest_url( 'ca-manager/v1/context-ad/click' )
					)
					: '',
				'genre'       => isset( $ad['genre'] ) ? (string) $ad['genre'] : '',
			),
		)
	);
}

/**
 * Headless クライアント用のクリック計測・遷移エンドポイント。
 */
function cam_rest_context_ad_click( WP_REST_Request $request ) {
	$ad_id     = sanitize_text_field( (string) $request->get_param( 'ad_id' ) );
	$placement = sanitize_key( (string) $request->get_param( 'placement' ) );
	$post_id   = absint( $request->get_param( 'post_id' ) );
	$genre     = sanitize_text_field( (string) $request->get_param( 'genre' ) );

	if (
		'' === $ad_id ||
		! in_array( $placement, array( 'top', 'middle', 'bottom' ), true ) ||
		! get_post( $post_id )
	) {
		return new WP_Error(
			'cam_context_ad_invalid_click',
			'Invalid context ad click.',
			array( 'status' => 400 )
		);
	}

	$ad = cam_get_context_ad_for_post_and_placement( $post_id, $placement );

	if (
		! is_array( $ad ) ||
		! isset( $ad['id'] ) ||
		$ad_id !== (string) $ad['id']
	) {
		return new WP_Error(
			'cam_context_ad_not_selected',
			'The specified ad is not active for this placement.',
			array( 'status' => 400 )
		);
	}

	$destination = isset( $ad['destination'] ) ? esc_url_raw( (string) $ad['destination'] ) : '';

	if ( '' === $destination ) {
		return new WP_Error(
			'cam_context_ad_missing_destination',
		'The selected ad has no destination.',
			array( 'status' => 404 )
		);
	}

	cam_log_context_ad_click(
		array(
			'ad_id'     => $ad_id,
			'placement' => $placement,
			'post_id'   => $post_id,
			'genre'     => $genre,
		)
	);

	wp_redirect( $destination, 302 );
	exit;
}

/**
 * Astro など静的フロントエンドからの広告計測イベントを記録する。
 *
 * @return WP_REST_Response|WP_Error
 */
function cam_rest_log_context_ad_event( WP_REST_Request $request ) {
	$params = $request->get_json_params();

	if ( ! is_array( $params ) ) {
		$params = $request->get_params();
	}

	$event     = isset( $params['event'] ) ? sanitize_key( (string) $params['event'] ) : '';
	$ad_id     = isset( $params['ad_id'] ) ? sanitize_text_field( (string) $params['ad_id'] ) : '';
	$post_id   = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
	$placement = isset( $params['placement'] ) ? sanitize_key( (string) $params['placement'] ) : '';
	$genre     = isset( $params['genre'] ) ? sanitize_text_field( (string) $params['genre'] ) : '';
	$seconds   = isset( $params['seconds'] ) ? absint( $params['seconds'] ) : 0;

	if (
		'' === $ad_id ||
		! in_array( $placement, array( 'top', 'middle', 'bottom' ), true ) ||
		! get_post( $post_id )
	) {
		return new WP_Error(
			'cam_context_ad_invalid_event',
			'Invalid context ad event.',
			array( 'status' => 400 )
		);
	}

	$selected_ad = cam_get_context_ad_for_post_and_placement( $post_id, $placement );

	if (
		! is_array( $selected_ad ) ||
		! isset( $selected_ad['id'] ) ||
		$ad_id !== (string) $selected_ad['id']
	) {
		return new WP_Error(
			'cam_context_ad_not_selected',
			'The specified ad is not active for this placement.',
			array( 'status' => 400 )
		);
	}

	if ( 'impression' === $event ) {
		cam_log_context_ad_impression(
			array(
				'ad_id'     => $ad_id,
				'placement' => $placement,
				'post_id'   => $post_id,
				'genre'     => $genre,
			)
		);
	} elseif ( 'bottom_reach' === $event && 'bottom' === $placement ) {
		cam_log_context_ad_bottom_reach(
			array(
				'ad_id'   => $ad_id,
				'post_id' => $post_id,
				'genre'   => $genre,
			)
		);
	} elseif (
		'time_reach' === $event &&
		'bottom' === $placement &&
		in_array( $seconds, array( 10, 30, 60 ), true )
	) {
		cam_log_context_ad_time_reach(
			array(
				'ad_id'   => $ad_id,
				'post_id' => $post_id,
				'genre'   => $genre,
				'seconds' => $seconds,
			)
		);
	} else {
		return new WP_Error(
			'cam_context_ad_invalid_event_type',
			'Invalid event type.',
			array( 'status' => 400 )
		);
	}

	return rest_ensure_response( array( 'logged' => true ) );
}

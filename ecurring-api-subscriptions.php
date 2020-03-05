<?php
/**
 * Ecurring subscription integration
 * 
 * That class get subscriptions info for WordPress users by email
 * Add users with active subscriptions from eCurring to WordPress site
 */
if ( ! class_exists( 'EcurringApiSubscription' ) ) {

	class EcurringApiSubscription {

		private static $ecu_api_key = 'YOUR ERECURRING API KEY';

		public static function customer_subscriptions() {

			$customer_id = self::get_user_customer_id();

			if ( $customer_id ) {

				$response_body = self::request( 'https://api.ecurring.com/customers/' . $customer_id );

				if ( isset( $response_body['data']['relationships']['subscriptions']['data'] )
				     && count( $response_body['data']['relationships']['subscriptions']['data'] ) ) {
					return self::get_subscriptions_data( $response_body['data']['relationships']['subscriptions']['data'] );
				}
			}

			return array();
		}

		public static function cancel_subscription() {

			$customer_id = self::get_user_customer_id();
			if ( $customer_id ) {
				$response_body = self::request( 'https://api.ecurring.com/customers/' . $customer_id );

				if ( isset( $response_body['data']['relationships']['subscriptions']['data'] )
				     && count( $response_body['data']['relationships']['subscriptions']['data'] ) ) {
					$subscriptions = self::get_subscriptions_data( $response_body['data']['relationships']['subscriptions']['data'] );

					if ( count( $subscriptions ) ) {
						$subscription_id = $subscriptions[0]['subscription_id'];
						$cancel_date     = date( DateTime::ISO8601, strtotime( $subscriptions[0]['start_date'] . ' +30 days' ) );
						$response        = self::request_patch( 'https://api.ecurring.com/subscriptions/' . $subscription_id, $subscription_id, $cancel_date );

						if ( isset( $response['data']['attributes']['status'] ) && 'cancelled' == $response['data']['attributes']['status'] ) {
							$time_expired = strtotime( $response['data']['attributes']['cancel_date'] );

							update_field( 'subscription_end_date', date( 'd.m.Y', $time_expired ), 'user_' . get_current_user_id() );
							wp_redirect( home_url() );
							die;
						}
					}
				}
			}
		}

		/**
		 * Import new customers to users wordpress
		 */
		public static function import_users() {

			$customers = self::get_customers();
			if ( 0 === count( $customers ) ) {
				return false;
			}

			require_once( ABSPATH . 'wp-admin/includes/user.php' );

			$users = get_users( array(
				'role' => 'ecurring_role',
			) );

			$emails = array();
			foreach ( $users as $user ) {
				$emails[] = $user->user_email;
			}

			foreach ( $customers as $customer ) {
				if ( ! in_array( $customer['email'], $emails )
				     || 0 === count($customer['subscriptions'])) {
					continue;
				}

				$active_subscriber = false;
				foreach ($customer['subscriptions'] as $subscription) {
					$subscription_info = self::get_subscription_info($subscription['id']);

					if ('active' == $subscription_info['data']['attributes']['status']) {
						$active_subscriber = true;
						break;
					}
				}

				if ($active_subscriber) {
					$userdata = array(
						'user_login' => strtolower( trim( $customer['first_name'] ) ) . wp_generate_password( 3, false, false ),
						'user_pass'  => wp_generate_password( 12 ),
						'user_email' => $customer['email'],
						'first_name' => $customer['first_name'],
						'last_name'  => $customer['last_name'],
						'role'       => 'ecurring_role',
					);

					wp_insert_user( $userdata ) ;
				}
			}
		}

		/**
		 * Get customer id from wordpress users
		 *
		 * @return int
		 */
		private static function get_user_customer_id() {

			$customer_id = get_field( 'ecurring_customer_id', 'user_' . get_current_user_id() );

			if ( empty( $customer_id ) ) {
				$customer_id = self::get_customer_id();
				if ( $customer_id ) {
					update_field( 'ecurring_customer_id', $customer_id, 'user_' . get_current_user_id() );
				}
			}

			return $customer_id;
		}

		/**
		 * Get ecurring customer id by wordpress email
		 *
		 * @return int
		 */
		private static function get_customer_id() {

			$customers = self::get_customers();

			if ( count( $customers ) ) {
				$current_user_data = get_userdata( get_current_user_id() );
				$user_email        = $current_user_data->user_email;

				foreach ( $customers as $customer ) {
					if ( $customer['email'] == $user_email ) {
						return $customer['id'];
					}
				}
			}

			return 0;
		}


		private static function get_customers( $page = 1 ) {

			$customers = array();

			$response_body = self::request( 'https://api.ecurring.com/customers?page[number]=' . $page . '&page[size]=100' );

			if ( count( $response_body['data'] ) ) {
				foreach ( $response_body['data'] as $customer ) {
					$customers[] = array(
						'id'         => $customer['id'],
						'email'      => $customer['attributes']['email'],
						'first_name' => $customer['attributes']['first_name'],
						'last_name'  => $customer['attributes']['last_name'],
						'subscriptions' => $customer['relationships']['subscriptions']['data']
					);
				}
			}

			if ( ! empty( $response_body['links']['next'] ) ) {
				$customers = array_merge( $customers, self::get_customers( $page + 1 ) );
			}

			return $customers;
		}

		private static function get_subscriptions_data( $subscriptions_data ) {

			$subscriptions = array();

			foreach ( $subscriptions_data as $subscription ) {
				$subscription_info    = self::get_subscription_info( $subscription['id'] );
				$subscription_plan_id = $subscription_info['data']['relationships']['subscription-plan']['data']['id'];

				$subscription_plan_info = self::get_subscription_plan_info( $subscription_plan_id );

				$subscriptions[] = array(
					'subscription_id' => $subscription['id'],
					'plan_name'       => $subscription_plan_info['data']['attributes']['name'],
					'start_date'      => $subscription_info['data']['attributes']['start_date'],
				);
			}

			return $subscriptions;
		}

		private static function get_subscription_info( $subscription_id ) {

			$response_body = self::request( 'https://api.ecurring.com/subscriptions/' . $subscription_id );

			return $response_body;
		}

		private static function get_subscription_plan_info( $subscription_plan_id ) {

			$response_body = self::request( 'https://api.ecurring.com/subscription-plans/' . $subscription_plan_id );

			return $response_body;
		}

		private static function request( $url ) {

			$args = array(
				'headers' => array(
					'Accept'          => 'application/json, application/vnd.api+json',
					'X-Authorization' => self::$ecu_api_key,
				),
			);

			$response = wp_remote_get( $url, $args );
			$body     = wp_remote_retrieve_body( $response );

			return json_decode( $body, true );
		}

		private static function request_patch( $url, $subscription_id, $cancel_date ) {

			$headers = array(
				'Content-Type'    => 'application/vnd.api+json',
				'Accept'          => 'application/vnd.api+json',
				'X-Authorization' => self::$ecu_api_key,
			);

			$data = array(
				'data' => array(
					'type'       => 'subscription',
					'id'         => $subscription_id,
					'attributes' => array(
						'cancel_date' => $cancel_date,
					),
				)
			);

			$response = Requests::patch( $url, $headers, json_encode( $data ) );

			return json_decode( $response->body, true );
		}
	}
}

if ( isset( $_GET['cancel_subscription'] ) && class_exists( 'EcurringApiSubscription' ) ) {
	EcurringApiSubscription::cancel_subscription();
}
?>

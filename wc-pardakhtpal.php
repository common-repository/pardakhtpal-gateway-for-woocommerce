<?php
defined( 'ABSPATH' ) OR exit;
/**
 * Plugin Name: Pardakhtpal Gateway for woocommerce
 * Plugin URI: -
 * Description: This plugin lets you use pardakhtpal gateway in woocommerce.
 * Version: 1.0
 * Author: wp-magic
 * Author URI: wp-src.ir
 * License: GPL2
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

add_action( 'plugins_loaded', 'init_pardakhtpal_wc_gateway_class', 0 );

function init_pardakhtpal_wc_gateway_class() {
    
    add_filter( 'woocommerce_payment_gateways', 'pardakhtpal_wc_class' );
    /**
     * Telling woocommerce that our gateway exists
     * @param mixed $methods 
     * @return mixed
     */
    function pardakhtpal_wc_class( $methods ) {
	    $methods[] = 'Woocommerce_Pardakhtpal'; 
	    return $methods;
    }
    
    if( ! class_exists('WC_Payment_Gateway') )
        return;
    
    /**
     * initializing our plugin class
     */
    class Woocommerce_Pardakhtpal extends WC_Payment_Gateway {
        
        /**
         * Constructor
         */
        public function __construct(){
            $this->id = 'pardakhtpal';
            $this->method_title = 'پرداخت پال';
            $this->method_description = 'تنظیمات درگاه پرداخت پال برای ووکامرس';
            $this->icon = plugin_dir_url( __FILE__ ) . 'images/logo.png';
            $this->has_fields = false;
            
            $this->init_form_fields();
            $this->init_settings();
            $this->title = $this->get_option( 'title' );
            if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) )
                add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            else
                add_action( 'woocommerce_update_options_payment_gateways', array( $this, 'process_admin_options' ) );	
            add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'wc_pardakhtpal_send' ) );
            add_action( 'woocommerce_api_woocommerce_pardakhtpal', array( $this, 'wc_pardakhtpal_check_result' ) );
        }
        
        public function init_form_fields(){
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'فعال / غیرفعال سازی',
                    'type' => 'checkbox',
                    'label' =>  'فعال یا غیرفعال سازی درگاه پرداخت پال',
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' =>  'عنوان نمایشی درگاه',
                    'type' => 'text',
                    'description' => 'این عنوان به هنگام خرید به کاربر نمایش داده می شود.',
                    'default' => '',
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => 'توضیح برای درگاه',
                    'type' => 'textarea',
                    'default' => 'پرداخت ایمن و سریع توسط درگاه پرداخت پال'
                ),
                'api' => array(
                    'title'       => 'کد اتصال',
                    'type'        => 'text',
                    'description' => 'کد اتصال یا همان API دریافتی از پرداخت پال را در این قسمت وارد نمایید',
                    'default'     => '',
                    'desc_tip'    => true
                )
            );
        }
        
        /**
         * Process Payment
         * 
         */
        function process_payment( $order_id ) {
            $order = new WC_Order( $order_id );	
            return array(
                'result'   => 'success',
                'redirect' => $order->get_checkout_payment_url(true)
            );
        }
        
        /**
         * Sending data to pardakhtpal
         * 
         */
        public function wc_pardakhtpal_send( $order_id ){
            global $woocommerce;
            $order = new WC_Order( $order_id );

            $target_url = 'http://www.pardakhtpal.com/WebService/WebService.asmx?wsdl';

            $API = $this->settings['api']; //Required 
            $Amount = intval( $order->order_total ); // Required
            $Description = 'پرداخت فاکتور به شماره ی' . $order->get_order_number() . '| نام و نام خانوادگی: ' . $order->billing_first_name . ' ' . $order->billing_last_name . '| ایمیل: ' . $order->billing_email . '| تلفن: ' . $order->billing_phone; // Required 
            $CallbackURL =  add_query_arg( 'wc_order', $order_id , WC()->api_request_url( 'Woocommerce_Pardakhtpal' ) );
            $OrderId = $order->get_order_number(); // Required 
            $currency = $order->get_order_currency();

            if ( strtolower( $currency ) == strtolower('IRT') || strtolower( $currency ) == strtolower( 'TOMAN' )
                || strtolower( $currency ) == strtolower( 'Iran TOMAN' ) || strtolower( $currency ) == strtolower( 'Iranian TOMAN' )
                || strtolower( $currency ) == strtolower( 'Iran-TOMAN' ) || strtolower( $currency ) == strtolower( 'Iranian-TOMAN' )
                || strtolower( $currency ) == strtolower( 'Iran_TOMAN' ) || strtolower( $currency ) == strtolower( 'Iranian_TOMAN' )
                || strtolower( $currency ) == strtolower( 'تومان' ) || strtolower( $currency ) == strtolower( 'تومان ایران' )
            )
                $Amount = $Amount*10;
            else if ( strtolower( $currency ) == strtolower( 'IRHT' ) )							
                $Amount = $Amount*1000*10;
            else if ( strtolower( $currency ) == strtolower( 'IRHR' ) )							
                $Amount = $Amount*1000;
            
            $client = new SoapClient( $target_url ); 

            $params = array( 'API' => $API , 'Amount' => $Amount, 'CallBack' => $CallbackURL, 'OrderId' => $OrderId, 'Text' => $Description );

            $res = $client->requestpayment( $params ); 
            $Result = $res->requestpaymentResult; 
            
            if( strlen($Result) == 8 ){
                $woocommerce->session->pardakhtpal_order = $order_id;
                
                $payment_url = 'http://www.pardakhtpal.com/payment/pay_invoice/';
                
                Header( "Location: $payment_url" . $Result ); 
            }
            else{
                wc_add_notice( 'خطا در بررسی اولیه ی تراکنش.', 'error' );
                return;
            }
        }
        
        /**
         * After payment completed we hook to the result with this function
         * 
         */
        public function wc_pardakhtpal_check_result(){
            global $woocommerce;
            //do_action( 'WC_Gateway_Payment_Actions', $action );
            if ( isset( $_GET['wc_order'] ) ) 
                $order_id = $_GET['wc_order'];
            else
                $order_id = $woocommerce->session->pardakhtpal_order;
            if ( $order_id ) {

                $order = new WC_Order( $order_id );
                $API = $this->settings['api']; //Required 
                $Amount = $order->order_total; //  - ریال به مبلغ Required 
                $Authority = $_POST['au'];
               
                if( strlen( $Authority ) > 4 ){ 
				
					$currency = $order->get_order_currency();
                    if ( strtolower( $currency ) == strtolower('IRT') || strtolower( $currency ) == strtolower( 'TOMAN' )
						|| strtolower( $currency ) == strtolower( 'Iran TOMAN' ) || strtolower( $currency ) == strtolower( 'Iranian TOMAN' )
						|| strtolower( $currency ) == strtolower( 'Iran-TOMAN' ) || strtolower( $currency ) == strtolower( 'Iranian-TOMAN' )
						|| strtolower( $currency ) == strtolower( 'Iran_TOMAN' ) || strtolower( $currency ) == strtolower( 'Iranian_TOMAN' )
						|| strtolower( $currency ) == strtolower( 'تومان' ) || strtolower( $currency ) == strtolower( 'تومان ایران' )
					)
						$Amount = $Amount*10;
					else if ( strtolower( $currency ) == strtolower( 'IRHT' ) )							
						$Amount = $Amount*1000*10;
					else if ( strtolower( $currency ) == strtolower( 'IRHR' ) )							
						$Amount = $Amount*1000;
					
					
                    $target_url = 'http://www.pardakhtpal.com/WebService/WebService.asmx?wsdl';
                    
                    $client = new SoapClient( $target_url ); 
                    
                    $params = array( 'API' => $API , 'Amount' => intval($Amount), 'InvoiceId' => $Authority ); 

                    $res = $client->verifypayment( $params ); 
                    $Result = $res->verifypaymentResult; 
                    
                    if( $Result == 1 ){
                        wc_add_notice( 'پرداخت شما با موفقیت دریافت شد.', 'success' );
                        $order->add_order_note( 'پرداخت موفقیت آمیز' , 1 );
                        if ( $Authority && ( $Authority !=0 ) )
                            update_post_meta( $order_id, '_transaction_id', $Authority );
                        
                        $order->payment_complete( $Authority );
                        wp_redirect( add_query_arg( 'wc_status', 'success', $this->get_return_url( $order ) ) );
                    }
                    else{
                        wc_add_notice( 'خطایی به هنگام پرداخت پیش آمده. کد خطا عبارت است از :' . $Result . ' . برای آگاهی از دلیل خطا کد آن را به پرداخت پال ارائه نمایید.', 'error' );
                        $order->add_order_note( 'خطایی به هنگام پرداخت پیش آمده. کد خطا عبارت است از :' . $Result . ' . برای آگاهی از دلیل خطا کد آن را به پرداخت پال ارائه نمایید.' , 1 );
                        $order->update_status('failed', 'کد خطا: ' . $Result );
                        wp_redirect( add_query_arg( 'wc_status', 'error', $this->get_return_url( $order ) ) );
                    }
                }
                else{
                    wc_add_notice( 'به نظر می رسد عملیات پرداخت توسط شما لغو گردیده، اگر چنین نیست مجددا اقدام به پرداخت فاکتور نمایید.', 'error' );
                    $order->add_order_note( 'به نظر می رسد کاربر عملیات را لغو کرده است.' , 1 );
                    $order->update_status('failed', 'لغو عملیات' );
                    wp_redirect(  $woocommerce->cart->get_checkout_url()  );
                }
            }
        }
    }
}
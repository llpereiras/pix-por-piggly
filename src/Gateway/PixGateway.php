<?php
namespace Piggly\WC\Pix\Gateway;

use Exception;
use Piggly\Pix\Exceptions\InvalidPixCodeException;
use Piggly\Pix\Exceptions\InvalidPixKeyException;
use Piggly\Pix\Exceptions\InvalidPixKeyTypeException;
use Piggly\Pix\Parser;
use Piggly\Pix\Payload;
use Piggly\Pix\Reader;
use Piggly\Pix\StaticPayload;
use Piggly\WC\Pix\WP\Debug;
use Piggly\WC\Pix\WP\Helper as WP;
use WC_Admin_Settings;
use WC_Order;
use WC_Payment_Gateway;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Load gateway if woocommerce is available.
 *
 * @since      1.2.0 
 * @package    Piggly\WC\Pix
 * @subpackage Piggly\WC\Pix
 * @author     Caique <caique@piggly.com.br>
 * @author     Piggly Lab <dev@piggly.com.br>
 */
class PixGateway extends WC_Payment_Gateway 
{
	/**
	 * Startup payment gatewat component.
	 * 
	 * @since 1.2.0
	 */
	public function __construct()
	{
		$this->id = 'wc_piggly_pix_gateway';
		$this->icon = apply_filters( 'woocommerce_gateway_icon', WC_PIGGLY_PIX_PLUGIN_URL.'assets/pix-payment-icon.png' );
		$this->has_fields = false;
		$this->method_title = __( 'Pix', WC_PIGGLY_PIX_PLUGIN_NAME );
		$this->method_description = __( 'Habilite o pagamento de pedidos via Pix. Este plugin automaticamente adiciona as instruções Pix na Página de Obrigado e na Página do Pedido.', WC_PIGGLY_PIX_PLUGIN_NAME );
		$this->supports = array('products');

		// Method with all the options fields
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();
		$this->setup_settings();

		// When it is admin...
		if ( is_admin() ) 
		{ WP::add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, $this, 'process_admin_options'); }

		// This action hook loads the thank you page
		WP::add_action( 'woocommerce_thankyou_'.$this->id, $this, 'thankyou_page', 5, 1 );
		// Add method instructions in order details page 
		WP::add_action( 'woocommerce_order_details_before_order_table', $this, 'page_instructions', 5, 1);
		// Customer Emails
		WP::add_action( 'woocommerce_email_'.$this->email_position.'_order_table', $this, 'email_instructions', 10, 4 );
	
		// Create shortcode
		add_shortcode( 'pix-por-piggly', array($this, 'pix_shortcode') );
	}

	/**
	 * Init settings for gateways.
	 * 
	 * @since 1.2.0
	 * @return void
	 */
	public function setup_settings ()
	{	
		// All settings
		$this->title = $this->get_option( 'title', __('Faça um Pix', WC_PIGGLY_PIX_PLUGIN_NAME) );
		$this->description = $this->get_option( 'description', __('Você não precisa ter uma chave cadastrada. Pague os seus pedidos via Pix.', WC_PIGGLY_PIX_PLUGIN_NAME) );
		$this->pix_qrcode = $this->as_bool($this->get_option( 'pix_qrcode', 'no' ));
		$this->pix_copypast = $this->as_bool($this->get_option( 'pix_copypast', 'no' ));
		$this->pix_manual = $this->as_bool($this->get_option( 'pix_manual', 'no' ));
		$this->store_name = $this->get_option( 'store_name' );
		$this->merchant_name = $this->get_option( 'merchant_name' );
		$this->merchant_city = $this->get_option( 'merchant_city' );
		$this->key_type = $this->get_option( 'key_type', Parser::KEY_TYPE_RANDOM );
		$this->key_value = $this->get_option( 'key_value' );
		$this->email_status = $this->get_option( 'email_status', 'WC_Email_Customer_On_Hold_Order' );
		$this->email_position = $this->get_option( 'email_position', 'before' );
		$this->order_status = $this->get_option( 'order_status', 'wc-on-hold' );
		$this->instructions = $this->get_option( 'instructions', __('Faça o pagamento via PIX. O pedido número {{pedido}} será liberado assim que a confirmação do pagamento for efetuada.', WC_PIGGLY_PIX_PLUGIN_NAME) );
		$this->identifier = $this->get_option( 'identifier', '***');
		$this->receipt_page_value = $this->get_option( 'receipt_page_value' );
		$this->whatsapp = preg_replace('/^[5]{3,}/', '', $this->get_option( 'whatsapp' ));
		$this->telegram = $this->get_option( 'telegram' );
		$this->whatsapp_message = $this->get_option( 'whatsapp_message', __('Segue o comprovante para o pedido {{pedido}}:', WC_PIGGLY_PIX_PLUGIN_NAME) );
		$this->telegram_message = $this->get_option( 'telegram_message', __('Segue o comprovante para o pedido {{pedido}}:', WC_PIGGLY_PIX_PLUGIN_NAME) );
		$this->help_text = $this->as_bool($this->get_option( 'help_text', 'no' ));
		$this->debug = $this->as_bool($this->get_option('debug','no'));
		$this->auto_fix = $this->as_bool($this->get_option('auto_fix','no'));
		$this->discount = $this->get_option( 'discount', '' );		
		$this->discount_label = $this->get_option( 'discount_label', __('Desconto Pix Aplicado', WC_PIGGLY_PIX_PLUGIN_NAME) );	
		$this->hide_in_order = $this->as_bool($this->get_option('hide_in_order','no'));
		$this->bank = $this->get_option( 'bank', 0 );	
	}

	/**
	 * Setup all form fields.
	 * 
	 * @since 1.2.0
	 * @return void
	 */
	public function init_form_fields()
	{
		$this->form_fields = array(
			'enabled' => [],
			'help_text' => [],
			'unique_payment' => [],
			'pix_qrcode' => [],
			'pix_copypast' => [],
			'pix_manual' => [],
			'pix_code' => [],
			'title' => [],
			'description' => [],
			'store_name' => [],
			'merchant_name' => [],
			'merchant_city' => [],
			'key_type' => [],
			'email_status' => [],
			'email_position' => [],
			'order_status' => [],
			'discount' => [],
			'key_value' => [],
			'instructions' => [],
			'identifier' => [],
			'receipt_page_value' => [],
			'whatsapp' => [],
			'telegram' => [],
			'whatsapp_message' => [],
			'telegram_message' => []
		);
	}

	/**
	 * Load admin settings page, based in current screen
	 * at plugin section.
	 * 
	 * @since 1.2.0
	 * @return void
	 */
	public function admin_options () 
	{
		$screen = filter_input( INPUT_GET, 'screen', FILTER_SANITIZE_STRING );
		
		// Fix screen always to main when not valid...
		if ( empty($screen) || !in_array( $screen, ['main','pix','import','orders','receipt','testing','support','faq','news','shortcode']) )
		{ $screen = 'main'; }

		require_once(WC_PIGGLY_PIX_PLUGIN_PATH.'templates/admin/menu.php');

		// Data variable to use at required file
		$data = $this;
		// Helper text variable to use at required file
		$helpText = $data->help_text;

		require_once(WC_PIGGLY_PIX_PLUGIN_PATH.'templates/admin/'.$screen.'-settings.php');
	}

	/**
	 * Process Payment.
	 * 
	 * @param int $order_id Order ID.
	 * @since 1.2.0
	 * @return array
	 */
	public function process_payment ( $order_id ) 
	{
		global $woocommerce;

		// Load order
		$order = new WC_Order( $order_id );
		
		// Mark as on-hold (we're awaiting the payment)
		$order->update_status( 
			str_replace('wc-', '', $this->order_status), 
			__( 'Aguardando pagamento via Pix', \WC_PIGGLY_PIX_PLUGIN_NAME ) 
		);
 
		// Remove cart
		$woocommerce->cart->empty_cart();

		Debug::info(sprintf('Pagamento realizado via Pix para o pedido %s.', $order_id));
		
		// Return thank-you redirect
		return array(
			'result' 	=> 'success',
			'redirect'	=> $this->get_return_url( $order )
		);
	}

	/**
	 * Processes and saves options.
	 * If there is an error thrown, will continue to save 
	 * and validate fields, but will leave the erroring field out.
	 *
	 * @since 1.2.0
	 * @return bool was anything saved?
	 */
	public function process_admin_options()
	{
		// Get current screen
		$screen = filter_input( INPUT_GET, 'screen', FILTER_SANITIZE_STRING );

		// Fix screen always to main when not valid...
		if ( empty($screen) )
		{ $screen = 'main'; }

		// Not save when screen does not need to be save.
		if ( in_array( $screen, ['testing','faq','news','shortcode']) )
		{ return false; }

		// Field prefix
		$field = 'woocommerce_'.$this->id.'_';

		// If screen is "main"
		if ( $screen === 'main' )
		{
			$fields = [
				'enabled' => 'no',
				'debug' => 'no',
				'help_text' => 'no',
				'title' => __('Faça um Pix', \WC_PIGGLY_PIX_PLUGIN_NAME),
				'description' => __('Você não precisa ter uma chave cadastrada. Pague os seus pedidos via Pix.', WC_PIGGLY_PIX_PLUGIN_NAME),
				'store_name' => '',
				'pix_qrcode' => 'no',
				'pix_copypast' => 'no',
				'pix_manual' => 'no'
			];

			foreach ( $fields as $_field => $default )
			{
				if ( empty($_POST[$field.$_field]) )
				{ $_POST[$field.$_field] = $default; }
			}
			
			if ( $this->auto_fix )
			{
				// Fix
				$_POST[$field.'store_name'] = $this->replace_char($_POST[$field.'store_name']);
			}
		}

		// If screen is "pix"
		if ( $screen === 'pix' )
		{
			$keyValue = filter_input( INPUT_POST, $field.'key_value', FILTER_SANITIZE_STRING );
			$keyType  = filter_input( INPUT_POST, $field.'key_type', FILTER_SANITIZE_STRING );

			if ( empty($keyValue) || empty( $keyValue ) )
			{
				WC_Admin_Settings::add_error( __('A chave Pix não pode ser vazia.', \WC_PIGGLY_PIX_PLUGIN_NAME) ); 
				return false;
			}
			
			$required = array(
				'key_type' => __('Tipo da Chave', \WC_PIGGLY_PIX_PLUGIN_NAME),
				'key_value' => __('Chave PIX', \WC_PIGGLY_PIX_PLUGIN_NAME),
				'merchant_name' => __('Nome do Titular da Conta', \WC_PIGGLY_PIX_PLUGIN_NAME),
				'merchant_city' => __('Cidade do Titular da Conta', \WC_PIGGLY_PIX_PLUGIN_NAME),
				'instructions' => __('Instruções do PIX', \WC_PIGGLY_PIX_PLUGIN_NAME)
			);

			foreach ( $required as $key => $value )
			{
				$postValue = filter_input( INPUT_POST, $field.$key, FILTER_SANITIZE_STRING );

				if ( empty ( $postValue ) || is_null ( $postValue ) )
				{ 
					WC_Admin_Settings::add_error( sprintf(__('Por favor, preencha o campo `%s`.', \WC_PIGGLY_PIX_PLUGIN_NAME), $value) );
					return false;
				}
			}

			// Validates the key
			try
			{ Parser::validate($keyType,$keyValue); }
			catch ( InvalidPixKeyTypeException $e )
			{
				WC_Admin_Settings::add_error( __('Chave inválida: O tipo selecionado é incompatível.', \WC_PIGGLY_PIX_PLUGIN_NAME) ); 
				Debug::error(sprintf('Erro ao salvar Pix: %s',$e->getMessage()));
				return false;
			}
			catch ( InvalidPixKeyException $e )
			{
				WC_Admin_Settings::add_error( sprintf(__('Chave inválida: O valor `%s` é incompatível com o tipo de chave selecionado.', \WC_PIGGLY_PIX_PLUGIN_NAME), $keyValue) ); 
				Debug::error(sprintf('Erro ao salvar Pix: %s',$e->getMessage()));
				return false;
			}
			catch ( Exception $e )
			{
				WC_Admin_Settings::add_error( sprintf(__('Chave inválida: %s', \WC_PIGGLY_PIX_PLUGIN_NAME), $e->getMessage()) ); 
				Debug::error(sprintf('Erro ao salvar Pix: %s',$e->getMessage()));
				return false;
			}

			$fields = [
				'bank' => 0,
				'instructions' => __('Faça o pagamento via PIX. O pedido número {{pedido}} será liberado assim que a confirmação do pagamento for efetuada.', WC_PIGGLY_PIX_PLUGIN_NAME),
				'identifier' => '***'
			];

			foreach ( $fields as $_field => $default )
			{
				if ( empty($_POST[$field.$_field]) )
				{ $_POST[$field.$_field] = $default; }
			}

			if ( empty($_POST[$field.'auto_fix']) )
			{ $_POST[$field.'auto_fix'] = 'no'; }

			if ( $_POST[$field.'auto_fix'] !== 'no' )
			{
				// Fix
				$_POST[$field.'merchant_name'] = $this->replace_char($_POST[$field.'merchant_name']);
				$_POST[$field.'merchant_city'] = $this->replace_char($_POST[$field.'merchant_city']);
				$_POST[$field.'identifier'] = preg_replace('/[^A-Za-z0-9\*\{\}]+/', '', $_POST[$field.'identifier']);
			}
		}

		// If screen is "import"
		if ( $screen === 'import' ) 
		{
			// Get pix-code
			$pixCode = filter_input( INPUT_POST, $field.'pix_code', FILTER_SANITIZE_STRING );
			// Error
			$_POST['error'] = true;

			if ( empty($pixCode) )
			{
				WC_Admin_Settings::add_error( sprintf(__('O Código Pix precisa ser enviado.', \WC_PIGGLY_PIX_PLUGIN_NAME)) );
				return false;
			}

			if ( empty($_POST[$field.'auto_fix']) )
			{ $_POST[$field.'auto_fix'] = 'no'; }

			try
			{
				// Read pix data and save it...
				$reader = new Reader($pixCode);

				$_POST[$field.'key_value'] = $reader->getPixKey();
				$_POST[$field.'key_type']  = Parser::getKeyType($_POST[$field.'key_value']);

				if ( $_POST[$field.'auto_fix'] !== 'no' )
				{
					$_POST[$field.'merchant_name'] = $this->replace_char($reader->getMerchantName());
					$_POST[$field.'merchant_city'] = $this->replace_char($reader->getMerchantCity());
				}
				else
				{
					$_POST[$field.'merchant_name'] = $reader->getMerchantName();
					$_POST[$field.'merchant_city'] = $reader->getMerchantCity();
				}

				unset($_POST[$field.'pix_code']);
			}
			catch ( InvalidPixCodeException $e )
			{ 
				WC_Admin_Settings::add_error( __('O código Pix importado é inválido. Certifique-se que é um código "Pix Copia & Cola" válido.', \WC_PIGGLY_PIX_PLUGIN_NAME) ); 
				Debug::error(sprintf('Erro ao salvar Pix: %s',$e->getMessage()));
				return false;
			}
			catch ( InvalidPixKeyTypeException $e )
			{ 
				WC_Admin_Settings::add_error( __('O tipo da chave do código Pix importado é inválido.', \WC_PIGGLY_PIX_PLUGIN_NAME) ); 
				Debug::error(sprintf('Erro ao salvar Pix: %s',$e->getMessage()));
				return false;
			}
			catch ( InvalidPixKeyException $e )
			{ 
				WC_Admin_Settings::add_error( __('A chave do código Pix importado é inválida.', \WC_PIGGLY_PIX_PLUGIN_NAME) ); 
				Debug::error(sprintf('Erro ao salvar Pix: %s',$e->getMessage()));
				return false;
			}
			catch ( Exception $e )
			{ 
				WC_Admin_Settings::add_error( sprintf(__('Um erro foi capturado, informe ao suporte: %s', \WC_PIGGLY_PIX_PLUGIN_NAME), $e->getMessage()) ); 
				Debug::error(sprintf('Erro ao salvar Pix: %s',$e->getMessage()));
				return false;
			}
			
			// Error
			unset($_POST['error']);
		}

		// If screen is "orders"
		if ( $screen === 'orders' )
		{
			$fields = [
				'email_status' => 'WC_Email_Customer_On_Hold_Order',
				'email_position' => 'before',
				'order_status' => 'wc-on-hold',
				'hide_in_order' => 'no',
				'discount' => '',
				'discount_label', __('Desconto Pix Aplicado', \WC_PIGGLY_PIX_PLUGIN_NAME)
			];

			foreach ( $fields as $_field => $default )
			{
				if ( empty($_POST[$field.$_field]) )
				{ $_POST[$field.$_field] = $default; }
			}
		}

		// If screen is "receipt"
		if ( $screen === 'receipt' )
		{
			$fields = [
				'receipt_page_value' => '',
				'whatsapp' => '',
				'whatsapp_message' => __('Segue o comprovante para o pedido {{pedido}}:', WC_PIGGLY_PIX_PLUGIN_NAME),
				'telegram' => '',
				'telegram_message' => __('Segue o comprovante para o pedido {{pedido}}:', WC_PIGGLY_PIX_PLUGIN_NAME),
			];

			foreach ( $fields as $_field => $default )
			{
				if ( empty($_POST[$field.$_field]) )
				{ $_POST[$field.$_field] = $default; }
			}
		}

		// If screen is "support"
		if ( $screen === 'support' )
		{
			$fields = [
				'debug' => 'no'
			];

			foreach ( $fields as $_field => $default )
			{
				if ( empty($_POST[$field.$_field]) )
				{ $_POST[$field.$_field] = $default; }
			}
		}

		$currentSettings = get_option($this->get_option_key(), []);

		foreach ( $_POST as $key => $value )
		{
			if ( strpos($key, $field) !== false )
			{
				$key = str_replace($field, '', $key);
				$currentSettings[$key] = filter_var($value, \FILTER_SANITIZE_STRING);
			}
		}
		
		return update_option( $this->get_option_key(), apply_filters( 'woocommerce_settings_api_sanitized_fields_' . $this->id, $currentSettings ), 'yes' );
	}

	/**
	 * Show pix instructions when viewing the order, only
	 * when payment is waiting...
	 * 
	 * @since 1.2.0
	 * @return void
	 */
	public function page_instructions ( $order )
	{
		if( $this->is_payment_waiting($order) && $this->is_order_view() && !$this->hide_in_order )
		{ do_action( 'woocommerce_thankyou_'.$order->get_payment_method(), $order->get_id() ); }
	}
	
	/**
	 * Add content to the WC emails.
	 *
	 * @param WC_Order $order
	 * @param bool $sent_to_admin
	 * @param bool $plain_text
	 * @param WC_Email $email
	 * @since 1.2.0
	 * @return void
	 */
	public function email_instructions( $order, $sent_to_admin, $plain_text = false, $email ) 
	{
		if ( get_class($email) === $this->email_status 
				&& $order->get_payment_method() === $this->id ) 
		{
			// If pix Key is not set...
			if ( empty($this->key_value) ) 
			{ return; }

			$pixData = $this->get_pix_data( $order );

			wc_get_template(
				'email-woocommerce-pix.php',
				array_merge( $pixData, [ 'order' => $order] ),
				WC()->template_path().\WC_PIGGLY_PIX_DIR_NAME.'/',
				WC_PIGGLY_PIX_PLUGIN_PATH.'templates/'
			);
		}
	}
	
	/**
	 * Add pix template to thank you page.
	 * 
	 * @param WC_Order|int $order_id
	 * @since 1.2.0
	 * @return void
	 */
	public function thankyou_page ( $order_id ) 
	{
		// Getting order object
		$order = $order_id instanceof WC_Order ? $order_id : wc_get_order($order_id);

		// Return if $order not found.
		if ( !$order )
		{ return; }
		
		// If pix Key is not set...
		if ( empty($this->key_value) ) 
		{ return; }
		
		$pixData = $this->get_pix_data( $order );

		wc_get_template(
			'html-woocommerce-thank-you-page.php',
			$pixData,
			WC()->template_path().\WC_PIGGLY_PIX_DIR_NAME.'/',
			WC_PIGGLY_PIX_PLUGIN_PATH.'templates/'
		);
	}

	/**
	 * Add pix template when call the shortcode.
	 * 
	 * @param array $attrs
	 * @since 1.2.0
	 * @return void
	 */
	public function pix_shortcode ( $attrs )
	{
		$attrs = shortcode_atts( array('order_id' => null), $attrs );

		if ( !$this->enabled || empty($attrs['order_id']) )
		{ return; }

		// Get order
		$order = wc_get_order((int)$attrs['order_id']);

		if ( !$order )
		{ return; }

		// If order is not payment waiting, return...
		if ( !$this->is_payment_waiting($order) )
		{ return; }

		$this->thankyou_page($order);
	}
	
	/**
	 * Get an array with all pix data to use in templates.
	 * 
	 * @param WC_Order $order
	 * @since 1.2.0
	 * @return array
	 */
	public function get_pix_data ( WC_Order $order ) : array
	{
		$order_id = $order->get_order_number();
		$amount   = $order->get_total();
		
		$this->instructions       = str_replace('{{pedido}}', $order_id, $this->instructions);
		$this->receipt_page_value = str_replace('{{pedido}}', $order_id, $this->receipt_page_value);
		$this->whatsapp_message   = str_replace('{{pedido}}', $order_id, $this->whatsapp_message);
		$this->telegram_message   = str_replace('{{pedido}}', $order_id, $this->telegram_message);

		if ( !empty($this->identifier) )
		{ $this->identifier = str_replace('{{id}}', $order_id, $this->identifier); }

		// Pix payload
		$payload = $this->get_pix_payload(
			$this->key_type,
			$this->key_value,
			$this->store_name,
			$this->merchant_name,
			$this->merchant_city,
			$amount,
			$this->identifier
		);

		// Current pix code...
		$pixCode = $payload->getPixCode();

		// Get order meta data...
		$pixOrder = $order->get_meta('_wc_piggly_pix');
		// Need to refresh pix code
		$refresh = true;

		// Don't refresh pix code
		if ( !empty($pixOrder) 
				&& ( 
					!$this->is_payment_waiting($order) 
					|| $pixOrder['pix_code'] === $payload->getPixCode() 
				) 
			)
		{ $refresh = false; }

		// If don't need to refresh, get pix data from order meta data...
		if ( !$refresh )
		{
			$amount = $pixOrder['amount'];
			$this->key_value = $pixOrder['key_value'];
			$this->key_type = $pixOrder['key_type'];
			$this->identifier = $pixOrder['identifier'];
			$this->store_name = $pixOrder['store_name'];
			$this->merchant_name = $pixOrder['merchant_name'];
			$this->merchant_city = $pixOrder['merchant_city'];

			// Get alias for pix
			$this->key_type_alias = Parser::getAlias($this->key_type); 

			return array(
				'data' => $this,
				'pix' => $pixOrder['pix_code'],
				'qrcode' => $pixOrder['pix_qr'],
				'order_id' => $order_id,
				'amount' => $amount
			);
		}
		
		// Flush the pix code to the new update
		// Get alias for pix
		$this->key_type_alias = Parser::getAlias($this->key_type); 

		// Create a new QR Code
		$pixQR = $this->pix_qrcode && Payload::supportQrCode() ? $payload->getQRCode(Payload::OUTPUT_PNG, Payload::ECC_L) : '';

		try
		{
			// There is a QR Code to create...
			if ( !empty($pixQR) )
			{
				$upload     = wp_upload_dir();
				$uploadPath = $upload['basedir'].'/'.\WC_PIGGLY_PIX_DIR_NAME.'/qr-codes/';
				$uploadUrl  = $upload['baseurl'].'/'.\WC_PIGGLY_PIX_DIR_NAME.'/qr-codes/';
				$fileName   = 'order-'.$order->get_id().'.png';
				$file       = $uploadPath.$fileName;

				// Create folder if not exists...
				if ( ! file_exists( $uploadPath ) ) 
				{ wp_mkdir_p($uploadPath); }

				// Remove file if already exists...
				if ( file_exists($file) )
				{ unlink($file); }

				$img     = str_replace('data:image/png;base64,', '', $pixQR);
				$img     = str_replace(' ', '+', $img);
				$data_   = base64_decode($img);
				$success = file_put_contents($file, $data_);

				// If file was created...
				if ( $success )
				{ $pixQR = $uploadUrl.$fileName; }
			}
		}
		catch ( Exception $e )
		{ Debug::error(sprintf('Erro ao salvar Pix QR Code: %s',$e->getMessage())); }

		// Update order meta...
		$order->update_meta_data('_wc_piggly_pix', [
			'pix_code' => $pixCode, 
			'pix_qr' => $pixQR,
			'amount' => $amount,
			'key_value' => $this->key_value,
			'key_type' => $this->key_type,
			'identifier' => $this->identifier,
			'store_name' => $this->store_name,
			'merchant_name' => $this->merchant_name,
			'merchant_city' => $this->merchant_city,
		]);

		$order->save();

		return array(
			'data' => $this,
			'pix' => $pixCode,
			'qrcode' => $pixQR,
			'order_id' => $order_id,
			'amount' => $amount
		);
	}

	/**
	 * Get the pix payload object.
	 * 
	 * @param string $keyType
	 * @param string $keyValue
	 * @param string $storeName
	 * @param string $merchantName
	 * @param string $merchantCity
	 * @param float $amount
	 * @param string $identifier
	 * @since 1.2.0
	 * @return StaticPayload
	 */
	protected function get_pix_payload (
		string $keyType,
		string $keyValue,
		string $storeName,
		string $merchantName,
		string $merchantCity,
		float $amount,
		$identifier 
	) : StaticPayload
	{
		$payload = 
			(new StaticPayload())
				->setPixKey($keyType, $keyValue)
				->setDescription(sprintf(__('Compra em %s', \WC_PIGGLY_PIX_PLUGIN_NAME), $storeName))
				->setMerchantName($merchantName)
				->setMerchantCity($merchantCity)
				->setAmount((float)$amount);

		if ( !empty($identifier) )
		{ $payload->setTid($identifier); }

		$pix = intval(get_option('wc_piggly_pix_counter', 0 ));
		update_option('wc_piggly_pix_counter', $pix++);

		return $payload;
	}

	/**
	 * Return if order has payment waiting.
	 * 
	 * @param WC_Order $order
	 * @since 1.2.0
	 * @return bool
	 */
	protected function is_payment_waiting ( WC_Order $order ) : bool
	{ 
		return 
			$this->id === $order->get_payment_method() 
			&& $order->has_status([
						'new', 
						'on-hold', 
						str_replace('wc-', '', $this->order_status)
					]); 
	}

	/**
	 * Return if endpoint url is 'view-order'.
	 * 
	 * @since 1.2.0
	 * @return bool
	 */
	protected function is_order_view ()
	{ return is_wc_endpoint_url( 'view-order' ); }

	/**
	 * Tries to convert a value to bool.
	 * 
	 * @since 1.2.0
	 * @return bool
	 */
	public function as_bool ( $value ) : bool
	{
		if ( is_string( $value ) )
		{
			if ( $value === 'yes' || $value === 'true' || $value === true )
			{ return true; }
			else if ( $value === 'no' || $value === 'false' || $value === false )
			{ return false; } 
		}

		return (bool)$value;
	}

	/**
	 * Replaces any invalid character to a valid one.
	 * 
	 * @since 1.1.0
	 * @param string $str
	 * @return string
	 */
	private function replace_char ( string $str ) : string
	{
		$invalid = array("Á", "À", "Â", "Ä", "Ă", "Ā", "Ã", "Å", "Ą", "Æ", "Ć", "Ċ", "Ĉ", "Č", "Ç", "Ď", "Đ", "Ð", "É", "È", "Ė", "Ê", "Ë", "Ě", "Ē", "Ę", "Ə", "Ġ", "Ĝ", "Ğ", "Ģ", "á", "à", "â", "ä", "ă", "ā", "ã", "å", "ą", "æ", "ć", "ċ", "ĉ", "č", "ç", "ď", "đ", "ð", "é", "è", "ė", "ê", "ë", "ě", "ē", "ę", "ə", "ġ", "ĝ", "ğ", "ģ", "Ĥ", "Ħ", "Í", "Ì", "İ", "Î", "Ï", "Ī", "Į", "Ĳ", "Ĵ", "Ķ", "Ļ", "Ł", "Ń", "Ň", "Ñ", "Ņ", "Ó", "Ò", "Ô", "Ö", "Õ", "Ő", "Ø", "Ơ", "Œ", "ĥ", "ħ", "ı", "í", "ì", "î", "ï", "ī", "į", "ĳ", "ĵ", "ķ", "ļ", "ł", "ń", "ň", "ñ", "ņ", "ó", "ò", "ô", "ö", "õ", "ő", "ø", "ơ", "œ", "Ŕ", "Ř", "Ś", "Ŝ", "Š", "Ş", "Ť", "Ţ", "Þ", "Ú", "Ù", "Û", "Ü", "Ŭ", "Ū", "Ů", "Ų", "Ű", "Ư", "Ŵ", "Ý", "Ŷ", "Ÿ", "Ź", "Ż", "Ž", "ŕ", "ř", "ś", "ŝ", "š", "ş", "ß", "ť", "ţ", "þ", "ú", "ù", "û", "ü", "ŭ", "ū", "ů", "ų", "ű", "ư", "ŵ", "ý", "ŷ", "ÿ", "ź", "ż", "ž");
		$valid   = array("A", "A", "A", "A", "A", "A", "A", "A", "A", "AE", "C", "C", "C", "C", "C", "D", "D", "D", "E", "E", "E", "E", "E", "E", "E", "E", "G", "G", "G", "G", "G", "a", "a", "a", "a", "a", "a", "a", "a", "a", "ae", "c", "c", "c", "c", "c", "d", "d", "d", "e", "e", "e", "e", "e", "e", "e", "e", "g", "g", "g", "g", "g", "H", "H", "I", "I", "I", "I", "I", "I", "I", "IJ", "J", "K", "L", "L", "N", "N", "N", "N", "O", "O", "O", "O", "O", "O", "O", "O", "CE", "h", "h", "i", "i", "i", "i", "i", "i", "i", "ij", "j", "k", "l", "l", "n", "n", "n", "n", "o", "o", "o", "o", "o", "o", "o", "o", "o", "R", "R", "S", "S", "S", "S", "T", "T", "T", "U", "U", "U", "U", "U", "U", "U", "U", "U", "U", "W", "Y", "Y", "Y", "Z", "Z", "Z", "r", "r", "s", "s", "s", "s", "B", "t", "t", "b", "u", "u", "u", "u", "u", "u", "u", "u", "u", "u", "w", "y", "y", "y", "z", "z", "z");
		$str     = str_ireplace( $invalid, $valid, $str );
		$str     = preg_replace('/[\!\.\,\@\#\$\%\&\*\(\)\/\?\{\}]+/', ' ', $str);
		$str     = preg_replace('/[\s]{2,}/', ' ', $str);

		return $str;
	}
}
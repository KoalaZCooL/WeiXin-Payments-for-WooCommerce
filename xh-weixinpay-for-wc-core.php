<?php
if (! defined ( 'ABSPATH' ))
	exit (); // Exit if accessed directly

/**
 * WC api
 *
 * @author rain
 *        
 */
class xh_weixinpay_for_wc_core extends WC_Payment_Gateway {
	var $current_currency;
	var $multi_currency_enabled;
	var $supported_currencies;
	var $lib_path;
	var $charset;
	public function __construct() {
		//支持退款
		array_push($this->supports,'refunds');
		$this->current_currency = get_option ( 'woocommerce_currency' );
		$this->multi_currency_enabled = in_array ( 'woocommerce-multilingual/wpml-woocommerce.php', apply_filters ( 'active_plugins', get_option ( 'active_plugins' ) ) ) && get_option ( 'icl_enable_multi_currency' ) == 'yes';
		$this->supported_currencies = array (
				'RMB',
				'CNY' 
		);
		$this->lib_path = plugin_dir_path ( __FILE__ ) . 'lib';
		$this->charset = strtolower ( get_bloginfo ( 'charset' ) );
		if (! in_array ( $this->charset, array (
				'gbk',
				'utf-8' 
		) )) {
			$this->charset = 'utf-8';
		}
		$this->include_files ();
		
		$this->id = 'wechatpay';
		$this->icon = plugins_url ( 'images/wechatpay.png', __FILE__ );
		$this->has_fields = false;
		$this->method_title = __ ( 'WeChatPay', 'wechatpay' ); // checkout option title
		$this->order_button_text = __ ( 'Proceed to WeChatPay', 'wechatpay' );
		$this->notify_url = XH_WC_WeChat_URL.'/notify.php';
		
		$this->init_form_fields ();
		$this->init_settings ();
		
		$this->title = $this->get_option ( 'title' );
		$this->description = $this->get_option ( 'description' );
		$this->wechatpay_appID = $this->get_option ( 'wechatpay_appID' );
		$this->wechatpay_mchId = $this->get_option ( 'wechatpay_mchId' );
		$this->wechatpay_key = $this->get_option ( 'wechatpay_key' );
		$this->debug = $this->get_option ( 'debug' );
		$this->form_submission_method = $this->get_option ( 'form_submission_method' ) == 'yes' ? true : false;
		$this->order_title_format = $this->get_option ( 'order_title_format' );
		$this->exchange_rate = $this->get_option ( 'exchange_rate' );
		$this->order_prefix = $this->get_option ( 'order_prefix' );
		$this->ipn = null;
		
		if ('yes' == $this->debug) {
			Log::Init ( new CLogFileHandler ( plugin_dir_path ( __FILE__ ) . "logs/" . date ( 'Y-m-d' ) . '.log' ), 15 );
		}
		
		$this->enabled = 'yes'==$this->get_option ( 'enabled' );
		if(!xh_isWeixinClient()&&xh_isWebApp()){
			$disabled	=$this->get_option('xh_alipay_for_wc_disabled_in_mobile_browser');
			if($disabled=='yes'){
					$this->enabled=false;
				}
		}
	}
	
	public  function get_order_title($order,$limit=100,$trimmarker='...'){
		$title='#'.$order->id;
		$order_items =$order->get_items();
		$order_item_qty =count($order_items);
		if($order_item_qty>0){
			$title.='|';
			$index =0;
			foreach ($order_items as $item_id =>$item){
				$title.= $item['name'];
				if($index++<($order_item_qty-1)){
					$title.=',';
				}
			}
		}else{
			$title.='|'.get_option ( 'blogname' );
		}
	
		$title=substr($title, 0,$limit);
		$title=mb_strimwidth ( $title, 0,strlen($title), '...');
	
		return $title;
	}
	
	public function WX_Loop_Order_Status() {
		$order_id = $_GET ['orderId'];
		$order = new WC_Order ( $order_id );
		$isPaid = ! $order->needs_payment ();
		
		if ($isPaid) {
			$returnUrl = urldecode ( $this->get_return_url ( $order ) );
			echo json_encode ( array (
					'status' => 'Paid',
					'url' => $returnUrl 
			) );
		} else {
			echo json_encode ( array (
					'status' => 'non-payment' 
			) );
		}
		exit ();
	}
	function WX_enqueue_script_onCheckout() {
		$orderId = get_query_var ( 'order-pay' );
		$order = new WC_Order ( $orderId );
		if ("wechatpay" == $order->payment_method) {
			if (is_checkout_pay_page () && ! isset ( $_GET ['pay_for_order'] )) {
				wp_enqueue_script ( 'Woo_WX_Loop', plugins_url ( '/js/check_weichat_paid.js', __FILE__ ), array (
						'jquery' 
				), null );
			}
		}
	}
	
	public function check_wechatpay_response() {
		$xml = $GLOBALS ['HTTP_RAW_POST_DATA'];
		if(empty($xml)){
			$xml =file_get_contents("php://input");
		}
		Log::DEBUG ( ' message callback.' . print_r ( $xml, true ) );
		Log::DEBUG ( 'weChat Async IPN message callback.' );
		if ($this->isWeChatIPNValid ( $xml )) {
			Log::DEBUG ( 'weChat IPN is valid message.' );
			Log::DEBUG ( 'weChat Async IPN message:' . print_r ( $xml, true ) );
			$order_id = $this->ipn ['attach'];
			$order = new WC_Order ( $order_id );
			$order->payment_complete ($this->ipn ['transaction_id']);
			//$trade_no = $this->ipn ['transaction_id'];
			//update_post_meta ( $order_id, 'WeChatPay Trade No.', wc_clean ( $trade_no ) );
			
			$reply = new WxPayNotifyReply ();
			$reply->SetReturn_code ( "SUCCESS" );
			$reply->SetReturn_msg ( "OK" );
			WxpayApi::replyNotify ( $reply->ToXml () );
		} else {
			$reply = new WxPayNotifyReply ();
			$reply->SetReturn_code ( "FAIL" );
			$reply->SetReturn_msg ( "OK" );
			WxpayApi::replyNotify ( $reply->ToXml () );
		}
	}
	function include_files() {
		$lib = $this->lib_path;
		include_once ($lib . '/phpqrcode/phpqrcode.php');
		include_once ($lib . '/WxPay.Data.php');
		include_once ($lib . '/WxPay.Api.php');
		include_once ($lib . '/WxPay.Exception.php');
		include_once ($lib . '/WxPay.Notify.php');
		include_once ($lib . '/WxPay.Config.php');
		include_once ($lib . '/log.php');
	}

	public function process_refund( $order_id, $amount = null, $reason = ''){
		$WxCfg = $this->getWXCfg ();
		$order = new WC_Order ($order_id );
		if(!$order){
			return new WP_Error( 'invalid_order','错误的订单' );
		}
	
		$trade_no =$order->get_transaction_id();
		if (empty ( $trade_no )) {
			return new WP_Error( 'invalid_order', '未找到微信支付交易号或订单未支付' );
		}
	
		$total = ( int ) ($order->get_total () * 100);
		$amount = ( int ) ($amount * 100);
	
		if (! in_array (  get_woocommerce_currency(), array (
				'RMB',
				'CNY'
		) )) {
			$exchange_rate = floatval($this->get_option('exchange_rate'));
			if($exchange_rate<=0){
				$exchange_rate=1;
			}
				
			$total = round ( $total * $exchange_rate, 2 );
			$amount = round ( $amount * $exchange_rate, 2 );
		}
	
		if($amount<=0||$amount>$total){
			return new WP_Error( 'invalid_order',__('无效的退款金额' ,XH_WECHAT) );
		}
	
		$transaction_id = $trade_no;
		$total_fee = $total;
		$refund_fee = $amount;
	
		$input = new WxPayRefund ();
		$input->SetTransaction_id ( $transaction_id );
		$input->SetTotal_fee ( $total_fee );
		$input->SetRefund_fee ( $refund_fee );
	
		$input->SetOut_refund_no ( $order->id.time());
		$input->SetOp_user_id ( $WxCfg->getMCHID());
	
		try {
			$result = WxPayApi::refund ( $input,60 ,$WxCfg);
			if ($result ['result_code'] == 'FAIL' || $result ['return_code'] == 'FAIL') {
				Log::DEBUG ( " XHWxPayApi::orderQuery:" . json_encode ( $result ) );
				throw new Exception ("return_msg:". $result ['return_msg'].';err_code_des:'. $result ['err_code_des'] );
			}
	
		} catch ( Exception $e ) {
			return new WP_Error( 'invalid_order',$e->getMessage ());
		}
	
		return true;
	}
	
	function is_available() {
		return $this->enabled ;
	}
	
	/**
	 * Initialise Gateway Settings Form Fields
	 *
	 * @access public
	 * @return void
	 */
	function init_form_fields() {
		$this->form_fields = array (
				'enabled' => array (
						'title' => __ ( 'Enable/Disable', 'wechatpay' ),
						'type' => 'checkbox',
						'label' => __ ( 'Enable WeChatPay Payment', 'wechatpay' ),
						'default' => 'no' 
				),
				'title' => array (
						'title' => __ ( 'Title', 'wechatpay' ),
						'type' => 'text',
						'description' => __ ( 'This controls the title which the user sees during checkout.', 'wechatpay' ),
						'default' => __ ( 'WeChatPay', 'wechatpay' ),
						//'desc_tip' => true ,
						'css' => 'width:400px'
				),
				'description' => array (
						'title' => __ ( 'Description', 'wechatpay' ),
						'type' => 'textarea',
						'description' => __ ( 'This controls the description which the user sees during checkout.', 'wechatpay' ),
						'default' => __ ( "Pay via WeChatPay, if you don't have an WeChatPay account, you can also pay with your debit card or credit card", 'wechatpay' ),
						//'desc_tip' => true ,
						'css' => 'width:400px'
				),
				'wechatpay_appID' => array (
						'title' => __ ( 'Application ID', 'wechatpay' ),
						'type' => 'text',
						'description' => __ ( 'Please enter the Application ID,If you don\'t have one, <a href="https://pay.weixin.qq.com" target="_blank">click here</a> to get.', 'wechatpay' ),
						'css' => 'width:400px' 
				),
				'wechatpay_mchId' => array (
						'title' => __ ( 'Merchant ID', 'wechatpay' ),
						'type' => 'text',
						'description' => __ ( 'Please enter the Merchant ID,If you don\'t have one, <a href="https://pay.weixin.qq.com" target="_blank">click here</a> to get.', 'wechatpay' ),
						'css' => 'width:400px' 
				),
				'wechatpay_key' => array (
						'title' => __ ( 'WeChatPay Key', 'wechatpay' ),
						'type' => 'text',
						'description' => __ ( 'Please enter your WeChatPay Key; this is needed in order to take payment.', 'wechatpay' ),
						'css' => 'width:400px',
						//'desc_tip' => true 
				),
				'xh_alipay_for_wc_disabled_in_mobile_browser' => array (
						'title' => __ ( 'Disabled in mobile browser', 'wechatpay' ),
						'type' => 'checkbox',
						'default' => 'no',
						'description' => '' 
				),
				'debug' => array (
						'title' => __ ( 'Debug Log', 'wechatpay' ),
						'type' => 'checkbox',
						'label' => __ ( 'Enable logging', 'wechatpay' ),
						'default' => 'no',
						'description' => sprintf ( __ ( 'Set the directory permissions to 777(%s)', 'wechatpay' ), '<code>[plugin]/logs/</code>' ) 
				) 
		);
		
		$this->form_fields ['exchange_rate'] = array (
					'title' => __ ( 'Exchange Rate', 'wechatpay' ),
					'type' => 'text',
					'default'=>1,
					'description' => sprintf ( __ ( "Please set the %s against Chinese Yuan exchange rate, eg if your currency is US Dollar, then you should enter 6.19", 'wechatpay' ), $this->current_currency ),
					'css' => 'width:80px;',
					'desc_tip' => true 
			);
	}
	
	/**
	 * Admin Panel Options
	 * - Options for bits like 'title' and account etc.
	 *
	 * @access public
	 * @return void
	 */
	public function admin_options() {
		?>
<h3>微信支付(免费版)</h3>
<p>
	企业版本支持微信原生支付（H5公众号）、微信登录、微信红包推广/促销、微信收货地址同步、微信退款等功能。<br />若需要企业版本，请联系QQ:<a
		href="http://wpa.qq.com/msgrd?v=3&uin=6347007&site=qq&menu=yes"
		target="_blank">6347007</a>或<a href="http://www.wpweixin.net/"
		target="_blank">迅虎网络</a>查看更多内容
</p>

<table class="form-table">
		            <?php
		// Generate the HTML For the settings form.
		$this->generate_settings_html ();
		?>
		        </table>
<!--/.form-table-->
<?php
	}
	public function process_payment($order_id) {
		$order = new WC_Order ( $order_id );
		return array (
				'result' => 'success',
				'redirect' => $order->get_checkout_payment_url ( true ) 
		);
	}
	function getWXURI($order_id) {
		$WxCfg = $this->getWXCfg ();
		$order = new WC_Order ( $order_id );
		$total = $order->get_total ();
		$totalFee = ( int ) ($total * 100);
		$input = new WxPayUnifiedOrder ();
		$input->SetBody ($this->get_order_title($order) );
		$input->SetDetail ( "" );
		$input->SetAttach ( $order_id );
		$input->SetOut_trade_no ( date ( "YmdHis" ) );
		
		if (! in_array ( $this->current_currency, array (
				'RMB',
				'CNY' 
		) )) {
			$this->exchange_rate = floatval($this->exchange_rate);
			if($this->exchange_rate<=0){$this->exchange_rate=1;}
			$totalFee = round ( $totalFee * $this->exchange_rate, 2 );
		}
		$input->SetTotal_fee ( $totalFee );
		
		$date = new DateTime ();
		$date->setTimezone ( new DateTimeZone ( 'Asia/Shanghai' ) );
		$startTime = $date->format ( 'YmdHis' );
		$expiredTime = $startTime + 600;
		
		$input->SetTime_start ( $startTime );
		//$input->SetTime_expire ( $expiredTime );
		// $input->SetGoods_tag("tag");
		$input->SetNotify_url ( $this->notify_url );
		//print $this->notify_url ;
		$input->SetTrade_type ( "NATIVE" );
		$input->SetProduct_id ( $order_id );
		$result = WxPayApi::unifiedOrder ( $input, 60, $WxCfg );
		Log::DEBUG ( 'Response of WxPayApi::unifiedOrder:' . print_r ( $result, true ) );
		return $result ["code_url"];
	}
	
	/*
	 * Validate ipn message is valid or not
	 */
	function isWeChatIPNValid($ipnXml) {
		
		// 如果返回成功则验证签名
		try {
			$result = WxPayResults::Init ( $ipnXml );
			$this->ipn = $result;
		} catch ( WxPayException $e ) {
			$msg = $e->errorMessage ();
			return false;
		}
		
		Log::DEBUG ( "call back  ipn:" . json_encode ( $result ) );
		
		if (! array_key_exists ( "transaction_id", $result )) {
			return false;
		}
		
		if (! $this->Queryorder ( $result ["transaction_id"] )) {
			return false;
		}
		
		return true;
	}
	
	/*
	 * Query transaction form weChat using transaction id in Ipn
	 */
	function Queryorder($transaction_id) {
		$WxCfg = $this->getWXCfg ();
		
		$input = new WxPayOrderQuery ();
		$input->SetTransaction_id ( $transaction_id );
		$result = WxPayApi::orderQuery ( $input, $WxCfg );
		Log::DEBUG ( " WxPayApi::orderQuery:" . json_encode ( $result ) );
		if (array_key_exists ( "return_code", $result ) && array_key_exists ( "result_code", $result ) && $result ["return_code"] == "SUCCESS" && $result ["result_code"] == "SUCCESS") {
			return true;
		}
		return false;
	}
	function genetateQR($order_id) {
		$baseQR = XH_WC_WeChat_URL . '/qrcode.php?data=';
		$url = urlencode ( urldecode ( $this->getWXURI ( $order_id ) ) );
		$qrUrl = $baseQR . $url;
		echo '<img id="WxQRCode" alt="QR Code" style="width:200px;height:200px" OId =' . $order_id . " loopUrl=" . $this->notify_url . " src=" . $qrUrl . '>';
	}
	function receipt_page($order) {
		if (! $this->qrUrl) {
			//Log::DEBUG ( 'Pay order with weChat payment' );
			echo '<p>' . __ ( 'Please scan the QR code with WeChat to finish the payment.', 'wechatpay' ) . '</p>';
			$this->genetateQR ( $order );
		}
	}
	function getWXCfg() {
		$weChatOptions = get_option ( 'woocommerce_wechatpay_settings' );
		$WxCfg = new WxPayConfig ( $weChatOptions ["wechatpay_appID"], $weChatOptions ["wechatpay_mchId"], $weChatOptions ["wechatpay_key"] );
		$WxCfg->setEnableProxy ( $weChatOptions ["WX_EnableProxy"] );
		if ($weChatOptions ["WX_EnableProxy"]) {
			$WxCfg->setCURLPROXYHOST ( $weChatOptions ["WX_ProxyHost"] );
			$WxCfg->setCURLPROXYPORT ( $weChatOptions ["WX_ProxyPort"] );
		}
		
		return $WxCfg;
	}
}

?>

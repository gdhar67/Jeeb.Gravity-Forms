<?php


/**
 * Class for handling Jeeb payment
 *
 * @link https://jeeb.com/bitcoin-payment-gateway-api
 */
class GFJeebPayment
{
    public $uid;            // Displays unique id
    public $total;          // Displays Total
    public $buyer_email;    // Displays Customer's EmaiL

    /**
     * Writes $contents to system error logger.
     *
     * @param mixed $contents
     * @throws Exception $e
     */
    public function error_log($contents)
    {
        if (false === isset($contents) || true === empty($contents)) {
            return;
        }

        if (true === is_array($contents)) {
            $contents = var_export($contents, true);
        } else if (true === is_object($contents)) {
            $contents = json_encode($contents);
        }

        error_log($contents);
    }

    public function convertIrrToBtc($url, $amount, $signature, $baseCur) {

        // return Jeeb::convert_irr_to_btc($url, $amount, $signature);
        $ch = curl_init($url.'currency?'.$signature.'&value='.$amount.'&base='.$baseCur.'&target=btc');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
          'Content-Type: application/json')
      );

      $result = curl_exec($ch);
      $data = json_decode( $result , true);
      error_log('Response =>'. var_export($data, TRUE));
      // Return the equivalent bitcoin value acquired from Jeeb server.
      return (float) $data["result"];

      }


      public function createInvoice($url, $amount, $options = array(), $signature) {

          $post = json_encode($options);

          $ch = curl_init($url.'payments/' . $signature . '/issue/');
          curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
          curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
          curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
          curl_setopt($ch, CURLOPT_HTTPHEADER, array(
              'Content-Type: application/json',
              'Content-Length: ' . strlen($post))
          );

          $result = curl_exec($ch);
          $data = json_decode( $result , true);
          error_log('data = '.$data['result']['token']);

          return $data['result']['token'];

      }

      public function redirectPayment($url, $token) {
        error_log("Entered into auto submit-form");
        // Using Auto-submit form to redirect user with the token
        echo "<form id='form' method='post' action='".$url."payments/invoice'>".
                "<input type='hidden' autocomplete='off' name='token' value='".$token."'/>".
               "</form>".
               "<script type='text/javascript'>".
                    "document.getElementById('form').submit();".
               "</script>";
      }

    /**
     * Process a payment
     */
    public function processPayment()
    {
      global $wpdb;
            if (true === empty(get_option('jeebRedirectURL'))) {
                update_option('jeebRedirectURL', get_site_url());
            }

            // price
            $price = number_format($this->total, 2, '.', '');

            $baseUri      = "https://core.jeeb.io/api/" ;
            $signature    = get_option('jeebSignature');
            $callBack     = get_option('jeebRedirectURL');
            $notification = get_option('siteurl').'/?jeeb_callback=true';
            $baseCur      = get_option('jeebBase');
            $lang         = get_option('jeebLang')== "none" ? NULL : get_option("jeebLang") ;
            $target_cur   = "";
            $order_total  = $price;
            $params = array(
                            'Btc',
                            'Xrp',
                            'Xmr',
                            'Ltc',
                            'Bch',
                            'Eth',
                            'TestBtc'
                           );

            foreach ($params as $p) {
              get_option("jeeb".$p) != NULL ? $target_cur .= get_option("jeeb".$p) . "/" : get_option("jeeb".$p) ;
              error_log("target cur = ". get_option("jeeb".$p));
            }

            error_log($this->uid." ".$baseUri." ".$signature." ".$callBack." ".$notification." ". $baseCur);
            error_log("target cur = ". $target_cur);

            $amount = $this->convertIrrToBtc($baseUri, $order_total, $signature, $baseCur);

            $params = array(
              'orderNo'          => $this->uid,
              'value'            => (float) $amount,
              'webhookUrl'       => $notification,
              'callBackUrl'      => $callBack,
              'allowReject'      => get_option('jeebNetwork')=="Testnet" ? false : true,
              "coins"            => $target_cur,
              "allowTestNet"     => get_option('jeebNetwork')=="Testnet" ? true : false,
              "language"         => $lang
            );

            $token = $this->createInvoice($baseUri, $amount, $params, $signature);

            $table_name = $wpdb->prefix.'jeeb_transactions';

            $data = array(
                'order_id' => $this->uid,
                'buyer_email' => $this->buyerEmail,
                'token'  => $token
            );

            $wpdb->insert($table_name, $data);

            $this->redirectPayment($baseUri, $token);

    }

}

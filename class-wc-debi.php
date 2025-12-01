<?php
/**
 * The core plugin class for Debi payment gateway.
 *
 * @package    WooCommerce_Debi
 * @author     Fernando del Peral <support@debi.pro>
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die;
}

/**
 * DEBIPRO_Payment_Gateway Payment Gateway
 *
 * @package    WooCommerce_Debi
 */
class DEBIPRO_Payment_Gateway extends WC_Payment_Gateway
{
    private $sandbox_mode;
    private $token_debi_live;
    private $token_debi_sandbox;

    public function __construct()
    {
        $this->id = 'debipro';
        $this->method_title = __('Debi Payment', 'debi-payment-for-woocommerce');
        $this->title = __('Debi Payment', 'debi-payment-for-woocommerce');
        $this->has_fields = true;
        $this->init_form_fields();
        $this->init_settings();
        $this->enabled = $this->get_option('enabled');
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->sandbox_mode = $this->get_option('sandbox_mode');
        $this->token_debi_live = $this->get_option('token_debi_live');
        $this->token_debi_sandbox = $this->get_option('token_debi_sandbox');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }
    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'debi-payment-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable Custom Payment', 'debi-payment-for-woocommerce'),
                'default' => 'no',
            ),
            'title' => array(
                'title' => __('Method Title', 'debi-payment-for-woocommerce'),
                'type' => 'text',
                'description' => __('This controls the title', 'debi-payment-for-woocommerce'),
                'default' => __('Credit or debit card in installments', 'debi-payment-for-woocommerce'),
                'desc_tip' => true,
            ),
            'sandbox_mode' => array(
                'title' => __('Sanbox mode', 'debi-payment-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Sandbox', 'debi-payment-for-woocommerce'),
                'default' => 'no',
                'description' => __('Check if you want to use sandbox mode for testing', 'debi-payment-for-woocommerce'),
            ),
            'token_debi_live' => array(
                'title' => __('Token Debi Live', 'debi-payment-for-woocommerce'),
                'type' => 'textarea',
                'css' => 'width:500px;',
                'default' => '',
                'description' => __('Generate token in developer section of debi', 'debi-payment-for-woocommerce'),

            ),
            'token_debi_sandbox' => array(
                'title' => __('Token Debi Sandbox', 'debi-payment-for-woocommerce'),
                'type' => 'textarea',
                'css' => 'width:500px;',
                'default' => '',
                'description' => __('Generate token in developer section of debi-test.pro', 'debi-payment-for-woocommerce'),

            ),
        );
    }

    public function process_payment($order_id)
    {
        global $woocommerce;
        $order = wc_get_order($order_id);

        if (!$order) {
            wc_add_notice(__('Order not found.', 'debi-payment-for-woocommerce'), 'error');
            return false;
        }

        // WooCommerce payment gateways handle nonce verification automatically
        // The nonce is verified by WooCommerce's process_payment() wrapper

        $items = $woocommerce->cart->get_cart();

        $product_id = null;
        $product_title = '';
        $monthly_interest_percentage = 0;
        
        foreach ($items as $item => $values) {
            $_product = wc_get_product($values['data']->get_id());
            $product_title = $_product->get_title();
            $product_id = $_product->get_id();
            
            // Get monthly interest percentage from product custom field
            $monthly_interest_percentage = get_post_meta($product_id, '_monthly_interest_percentage', true);
            $monthly_interest_percentage = is_numeric($monthly_interest_percentage) ? floatval($monthly_interest_percentage) : 0;
        }
        
        $name = $woocommerce->customer->get_billing_last_name() . ', ' . $woocommerce->customer->get_billing_first_name();
        $email = $woocommerce->customer->get_billing_email();

        // Determine which token to use based on sandbox mode
        $is_sandbox = $this->sandbox_mode === 'yes';
        $token = $is_sandbox ? $this->token_debi_sandbox : $this->token_debi_live;
        
        // Sanitize and validate input
        $quotas = isset($_POST[$this->id . '-cuotas']) ? absint(wp_unslash($_POST[$this->id . '-cuotas'])) : 0;
        
        if ($quotas < 1) {
            wc_add_notice(__('Invalid number of installments selected.', 'debi-payment-for-woocommerce'), 'error');
            return false;
        }
        
        // Calculate total interest based on monthly interest and number of installments
        $total_interest_percentage = $monthly_interest_percentage * $quotas;
        
        $final_price = (float)$order->get_total() + ((float)$order->get_total() * (float)$total_interest_percentage / 100);
        
        $DNIoCUIL = isset($_POST['participant_id']) ? sanitize_text_field(wp_unslash($_POST['participant_id'])) : '';
        $number = isset($_POST[$this->id . '-payment_method_number']) ? sanitize_text_field(wp_unslash($_POST[$this->id . '-payment_method_number'])) : '';
        
        // Validate card number
        if (empty($number)) {
            wc_add_notice(__('Card number is required.', 'debi-payment-for-woocommerce'), 'error');
            return false;
        }
        
        // Basic card number validation (should be numeric and have reasonable length)
        $number = preg_replace('/\D/', '', $number);
        if (empty($number) || strlen($number) < 13 || strlen($number) > 19) {
            wc_add_notice(__('Invalid card number.', 'debi-payment-for-woocommerce'), 'error');
            return false;
        }

        update_post_meta($order_id, '_debipro_final_price', sanitize_text_field($final_price));
        update_post_meta($order_id, '_debipro_installment_count', sanitize_text_field($quotas));
        update_post_meta($order_id, '_debipro_installment_amount', sanitize_text_field($final_price / $quotas));
        update_post_meta($order_id, '_debipro_card_last_four', sanitize_text_field(substr($number, -4)));

        if (gmdate('j') >= 29) {
            $day_of_month = 1;
        } else {
            $day_of_month = gmdate('j');
        }


        // Save customer to Debi
        $response_customer = (new DEBIPRO_debi($token, $is_sandbox))->request('customers', [
            'method' => 'POST',
            'body' => [
                'name' => $name,
                'email' => $email,
                'identification_number' => $DNIoCUIL,
            ],
        ]);

        $data_customer = $response_customer['data'];
        $customer_id = $data_customer['id'];


        // Tokenize payment method
        $response_payment_method = (new DEBIPRO_debi($token, $is_sandbox))->request('payment_methods', [
            'method' => 'POST',
            'body' => [
                'type' => 'card',
                'card' => [
                    'number' => $number,
                ]
            ],
        ]);

        $data_payment_method = $response_payment_method['data'];
        $payment_method_id = $data_payment_method['id'];

        $request = (new DEBIPRO_debi($token, $is_sandbox))->request('subscriptions', [
            'method' => 'POST',
            'body' => [
                'amount' => $final_price / $quotas,
                'description' => 'Order ' . $order->id . ' - Product ' . $product_id . ' - ' . $product_title,
                'payment_method_id' => $payment_method_id,
                'interval_unit' => "monthly",
                'interval' => 1,
                'day_of_month' => $day_of_month,
                'count' => $quotas,
                'customer_id' => $customer_id,
            ],
        ]);

        // Save subscription_id for future updates
        $data = $request['data'];
        $subscription_id = $data['id'];

        if (empty($subscription_id)) {
            return array(
                'result' => 'failure',
                'redirect' => $this->get_return_url($order),
            );
        } else {

            if (!empty($subscription_id)) {
                update_post_meta($order_id, '_debipro_subscription_id', sanitize_text_field($subscription_id));
            }

            // This also reduces stock (if cancelled later, it automatically increases)
            $order->update_status('processing');

            // Remove cart
            $woocommerce->cart->empty_cart();
            
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
            );
        }
    }

    /**
     * Get formatted installment text
     *
     * @param int $count Number of installments
     * @param string $quota_amount Formatted quota amount
     * @param string $final_amount Formatted final amount
     * @param string $extra_text Additional text to append (e.g., "- DEBIT CARD ONLY")
     * @return string Formatted installment text
     */
    private function get_installment_text($count, $quota_amount, $final_amount, $extra_text = '') {
        // translators: %1$d is the installment count, %2$s is the installment amount, %3$s is the total amount
        $singular_text = __('%1$d installment of $ %2$s ($ %3$s)', 'debi-payment-for-woocommerce');
        // translators: %1$d is the installment count, %2$s is the installment amount, %3$s is the total amount
        $plural_text = __('%1$d installments of $ %2$s ($ %3$s)', 'debi-payment-for-woocommerce');
        
        $text = ($count == 1) ? $singular_text : $plural_text;
        
        $formatted = sprintf($text, $count, $quota_amount, $final_amount);
        
        if (!empty($extra_text)) {
            $formatted .= $extra_text;
        }
        
        return $formatted;
    }

    /**
     * Get formatted installment text for no interest options
     *
     * @param int $count Number of installments
     * @param string $quota_amount Formatted quota amount
     * @return string Formatted installment text
     */
    private function get_installment_no_interest_text($count, $quota_amount) {
        // translators: %1$d is the installment count, %2$s is the installment amount
        $singular_text = __('%1$d installment of $ %2$s (no interest)', 'debi-payment-for-woocommerce');
        // translators: %1$d is the installment count, %2$s is the installment amount
        $plural_text = __('%1$d installments of $ %2$s (no interest)', 'debi-payment-for-woocommerce');
        
        $text = ($count == 1) ? $singular_text : $plural_text;
        
        return sprintf($text, $count, $quota_amount);
    }

    public function payment_fields()
    {
            global $woocommerce;
            $amount = $woocommerce->cart->total;

            // Get product info and custom fields for financing
            $product_id = null;
            $product_title = '';
            $monthly_interest_percentage = 0;
            $maximum_installments_allowed = 12;

            $items = $woocommerce->cart->get_cart();
            foreach ($items as $item => $values) {
                $_product = wc_get_product($values['data']->get_id());
                $product_title = $_product->get_title();
                $product_id = $_product->get_id();
                
                // Get custom fields for financing
                $monthly_interest_percentage = get_post_meta($product_id, '_monthly_interest_percentage', true);
                $maximum_installments_allowed = get_post_meta($product_id, '_maximum_installments_allowed', true);
                
                // Set defaults if not configured
                $monthly_interest_percentage = is_numeric($monthly_interest_percentage) ? floatval($monthly_interest_percentage) : 0;
                $maximum_installments_allowed = is_numeric($maximum_installments_allowed) && $maximum_installments_allowed > 0 ? intval($maximum_installments_allowed) : 12;
            }
?>

            <fieldset>
                <?php echo wp_kses_post($this->get_description()); ?>
                
                <p>
                    <label for="<?php echo esc_attr($this->id); ?>-cuotas"><?php esc_html_e('Select number of installments', 'debi-payment-for-woocommerce'); ?><span class="required">*</span></label>
                    <select id="<?php echo esc_attr($this->id); ?>-cuotas" name="<?php echo esc_attr($this->id); ?>-cuotas">
                        <option value="" disabled selected><?php esc_html_e('Select number of installments', 'debi-payment-for-woocommerce'); ?></option>
                        <?php
                        // Render installment options based on product's financing configuration
                        for ($i = 1; $i <= $maximum_installments_allowed; $i++) {
                            // Calculate interest for this number of installments
                            $total_interest_percentage = $monthly_interest_percentage * $i;
                            $final_amount = $amount + ($amount * $total_interest_percentage / 100);
                            $quota_amount = $final_amount / $i;
                            
                            $final_amount_formatted = number_format($final_amount, 2, ',', ' ');
                            $quota_amount_formatted = number_format($quota_amount, 2, ',', ' ');
                            
                            if ($monthly_interest_percentage == 0) {
                                // No interest option
                                $text = $this->get_installment_no_interest_text($i, $quota_amount_formatted);
                            } else {
                                // With interest option
                                $text = $this->get_installment_text($i, $quota_amount_formatted, $final_amount_formatted);
                            }
                            ?>
                            <option value="<?php echo esc_attr($i); ?>"><?php echo esc_html($text); ?></option>
                            <?php
                        }
                        ?>
                    </select>
                </p>

                <p class="form-row form-row-wide">
                    <label for="<?php echo esc_attr($this->id); ?>-payment"><?php esc_html_e('Enter your card number', 'debi-payment-for-woocommerce'); ?> <span class="required">*</span></label>
                    <input id="<?php echo esc_attr($this->id); ?>-payment" name="<?php echo esc_attr($this->id); ?>-payment_method_number"></input>
                </p>

                <div class="clear"></div>

            </fieldset>

<?php
    }
}

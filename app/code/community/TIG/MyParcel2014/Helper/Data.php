<?php

/**
 *                  ___________       __            __
 *                  \__    ___/____ _/  |_ _____   |  |
 *                    |    |  /  _ \\   __\\__  \  |  |
 *                    |    | |  |_| ||  |   / __ \_|  |__
 *                    |____|  \____/ |__|  (____  /|____/
 *                                              \/
 *          ___          __                                   __
 *         |   |  ____ _/  |_   ____ _______   ____    ____ _/  |_
 *         |   | /    \\   __\_/ __ \\_  __ \ /    \ _/ __ \\   __\
 *         |   ||   |  \|  |  \  ___/ |  | \/|   |  \\  ___/ |  |
 *         |___||___|  /|__|   \_____>|__|   |___|  / \_____>|__|
 *                  \/                           \/
 *                  ________
 *                 /  _____/_______   ____   __ __ ______
 *                /   \  ___\_  __ \ /  _ \ |  |  \\____ \
 *                \    \_\  \|  | \/|  |_| ||  |  /|  |_| |
 *                 \______  /|__|    \____/ |____/ |   __/
 *                        \/                       |__|
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 * If you are unable to obtain it through the world-wide-web, please send an email
 * to servicedesk@tig.nl so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this module to newer
 * versions in the future. If you wish to customize this module for your
 * needs please contact servicedesk@tig.nl for more information.
 *
 * @copyright   Copyright (c) 2013 Total Internet Group B.V. (http://www.tig.nl)
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 */
class TIG_MyParcel2014_Helper_Data extends Mage_Core_Helper_Abstract
{

    const XPATH_MYPARCEL_CONFIG_ACTIVE = 'tig_myparcel/general/active';

    /**
     * Address type used by PakjeGemak addresses.
     */
    const PG_ADDRESS_TYPE = 'pakje_gemak';

    /**
     * Regular expression used to split street name from house number. This regex works well for dutch addresses, but
     * may fail for international addresses. We strongly recommend using split address lines instead.
     *
     * Street (key street)
     * (?P<street>.*?)
     *
     * An Street and house number is sometimes separated by a whitespace
     * \s?
     *
     * Insert number and extension together in one array
     * (?P<street_suffix>
     *
     * Set number (int)
     * (?P<number>[\d]+)
     *
     * Sometimes an extension begins with a dash
     * -?
     *
     * Set key for extension
     * (?P<extension>
     *
     * If extension have text, / or whitespace
     * [a-zA-Z/\s]{0,5}$
     *
     * OR(!) if extension have a number
     * |[0-9/]{0,4}$
     *
     * Close key for extension
     * )
     *
     * Close number and extension together
     * )
     *
     */
    const SPLIT_STREET_REGEX = '~(?P<street>.*?)\s?(?P<street_suffix>(?P<number>[\d]+)-?(?P<extension>[a-zA-Z/\s]{0,5}$|[0-9/]{0,4}$))$~';

    /**
     * Regular expression used to split house number and house number extension
     * This data is the same as above
     */
    const SPLIT_HOUSENUMBER_REGEX = '~(?P<number>[\d]+)-?(?P<extension>[a-zA-Z/\s]{0,5}$|[0-9/]{0,4}$)~';

    /**
     * Log filename to log all non-specific MyParcel exceptions.
     */
    const MYPARCEL_EXCEPTION_LOG_FILE = 'TIG_MyParcel2014_Exception.log';

    /**
     * Log filename to log all non-specific MyParcel debug messages.
     */
    const MYPARCEL_DEBUG_LOG_FILE = 'TIG_MyParcel2014_Debug.log';

    /**
     * email address of the shop owner
     */
    const XML_PATH_EMAIL_IDENTITY = 'sales_email/order/identity';
    /**
     * Localised track and trace base URL's
     */
    const POSTNL_TRACK_AND_TRACE_NL_BASE_URL = 'https://mijnpakket.postnl.nl/Inbox/Search?';
    const POSTNL_TRACK_AND_TRACE_INT_BASE_URL = 'https://www.internationalparceltracking.com/Main.aspx#/track';

    /**
     * List of MyParcel shipping methods.
     *
     * @var null|array
     */
    protected $_myParcelShippingMethods = null;

    /**
     * Gets a config value for this module, automatically selecting the current store.
     *
     * @param string $value
     * @param string $group
     * @param int    $storeId to use in the backend, e.g. $order->getStoreId()
     * @param bool   $decrypt
     *
     * @return string
     */
    public function getConfig($value, $group = 'general', $storeId = null, $decrypt = false)
    {
        if (empty($storeId)) { // in case of frontend calls
            $storeId = Mage::app()->getStore()->getId();
        }
        $config = Mage::getStoreConfig('tig_myparcel/' . $group . '/' . $value, $storeId);

        if ($decrypt) {
            $config = Mage::helper('core')->decrypt($config);
        }

        return trim($config);
    }

    /**
     * Gets a PakjeGemak address for either a quote or an order object.
     *
     * @param Mage_Sales_Model_Quote|Mage_Sales_Model_Order $object
     *
     * @return false|Mage_Sales_Model_Order_Address|Mage_Sales_Model_Quote_Address|TIG_MyParcel2014_Model_Shipment
     */
    public function getPgAddress($object)
    {
        /**
         * Get all addresses for the specified object.
         */
        if ($object instanceof Mage_Sales_Model_Quote) {
            $addressCollection = $object->getAllAddresses();
        } elseif ($object instanceof Mage_Sales_Model_Order) {
            $addressCollection = $object->getAddressesCollection();
        } elseif ($object instanceof TIG_MyParcel2014_Model_Shipment) {
            $order = $object->getOrder();

            if (!$order) {
                return false;
            }

            $addressCollection = $order->getAddressesCollection();
        } else {
            return false;
        }

        /**
         * Go through each address and check if it's a PakjeGemak address.
         *
         * @var Mage_Sales_Model_Quote_Address|Mage_Sales_Model_Order_Address $address
         */
        $pgAddress = false;
        foreach ($addressCollection as $address) {
            if ($address->getAddressType() == self::PG_ADDRESS_TYPE) {
                $pgAddress = $address;
                break;
            }
        }

        /**
         * Return the PakjeGemak address or false if none was found.
         */

        return $pgAddress;
    }

    /**
     * Gets a list of MyParcel shipping methods.
     *
     * @return array
     */
    public function getMyParcelShippingMethods()
    {
        if ($this->_myParcelShippingMethods == null) {
            $shippingMethods = $this->getConfig('myparcel_shipping_methods');
            $shippingMethods = explode(',', $shippingMethods);

            $this->_myParcelShippingMethods = $shippingMethods;
        }

        return $this->_myParcelShippingMethods;
    }

    /**
     * Checks if a given shipping method is MyParcel.
     *
     * @param string $method
     *
     * @return boolean
     */
    public function shippingMethodIsMyParcel($method)
    {
        if($this->getConfig('always_myparcel') === '1') {
            return true;
        }

        $myParcelShippingMethods = $this->getMyParcelShippingMethods();

        $method = 'bolcom_bolcom' === $method ? 'bolcom_flatrate' : $method;
        $method = strpos($method, 'matrixrate_matrixrate') !== false ? 'matrixrate_matrixrate' : $method;
        if (in_array($method, $myParcelShippingMethods)) {
            return true;
        }

        return false;
    }

    /**
     * Checks if the given shipping method is Pakjegemak
     *
     * @param $method
     *
     * @return bool
     */
    public function shippingMethodIsPakjegemak($method)
    {
        $myParcelCarrier = Mage::getModel('tig_myparcel/carrier_myParcel');
        $myParcelCode = $myParcelCarrier->getCarrierCode();

        if ($method == $myParcelCode . '_pakjegemak') {
            return true;
        }

        return false;
    }

    /**
     * Get html for the MyParcel options
     *
     * @param TIG_MyParcel2014_Model_Shipment $myParcelShipment
     *
     * @return string
     */
    public function getCurrentOptionsHtml($myParcelShipment)
    {
        $options = array(
            $this->__(ucfirst(str_replace('_', ' ', $myParcelShipment->getShipmentType()))),
        );

        if ($myParcelShipment->getShipmentType() == 'normal') {

            if ($myParcelShipment->getHomeAddressOnly() == '1')
                $options[] = strtolower($this->__('Home address only'));

            if ($myParcelShipment->getHomeAddressOnly() == '1')
                $options[] = strtolower($this->__('Signature on receipt'));

            if ($myParcelShipment->getReturnIfNoAnswer() == '1')
                $options[] = strtolower($this->__('Return if no answer'));

            if ($myParcelShipment->getInsuredAmount() > 0)
                $options[] = strtolower($this->__('Insured up to &euro;%s', $myParcelShipment->getInsuredAmount()));

            if ($myParcelShipment->getIsXL() == '1')
                $options[] = strtolower($this->__('Large package'));

        }

        $htmlOptions = implode(', ', $options);

        return $htmlOptions;
    }

    /**
     * Returns the whiteList codes for customs.
     * @return array
     */
    public function whiteListCodes()
    {
        return array(
            'NL', 'BE', 'BG', 'DK', 'DE', 'EE', 'FI', 'FR', 'HU', 'IE',
            'IT', 'LV', 'LT', 'LU', 'MC', 'AT', 'PL', 'PT', 'RO', 'SI',
            'SK', 'ES', 'CZ', 'GB', 'SE'
        );
    }

    /**
     * Checks if country needs to have customs
     *
     * @param $countryCode
     *
     * @return bool
     */
    public function countryNeedsCustoms($countryCode)
    {
        $whitelisted = in_array($countryCode, $this->whiteListCodes());
        if (!$whitelisted) {
            return true;
        }

        return false;
    }

    /**
     * Constructs a track & trace url based on a barcode and the destination of the package (country and zipcode)
     *
     * @param string           $barcode
     * @param mixed            $destination An array or object containing the shipment's destination data
     * @param boolean | string $lang
     * @param boolean          $forceNl
     *
     * @return string
     */
    public function getBarcodeUrl($barcode, $destination = false, $lang = false, $forceNl = false)
    {
        $countryCode = null;
        $postcode = null;
        if (is_array($destination)) {
            if (!isset($destination['countryCode'])) {
                throw new InvalidArgumentException("Destination must contain a country code.");
            }

            $countryCode = $destination['countryCode'];
            $postcode = $destination['postcode'];
        } elseif (is_object($destination) && $destination instanceof Varien_Object) {
            if (!$destination->getCountry()) {
                throw new InvalidArgumentException('Destination must contain a country code.');
            }

            $countryCode = $destination->getCountry();
            $postcode = str_replace(' ', '', $destination->getPostcode());
        } else {
            throw new InvalidArgumentException('Destination must be an array or an instance of Varien_Object.');
        }

        /**
         * Get the dutch track & trace URL for dutch shipments or for the admin.
         */
        if ($forceNl
            || (!empty($countryCode)
                && $countryCode == 'NL'
            )
        ) {
            $barcodeUrl = self::POSTNL_TRACK_AND_TRACE_NL_BASE_URL
                . '&b=' . $barcode;
            /**
             * For dutch shipments add the postcode. For international shipments add an 'international' flag.
             */
            if (!empty($postcode)
                && !empty($countryCode)
                && $countryCode == 'NL'
            ) {
                $barcodeUrl .= '&p=' . $postcode;
            } else {
                $barcodeUrl .= '&i=true';
            }

            return $barcodeUrl;
        }

        /**
         * Get a general track & trace URL for all other destinations.
         */
        $barcodeUrl = self::POSTNL_TRACK_AND_TRACE_INT_BASE_URL
            . '/' . $barcode
            . '/' . $countryCode;

        if (!empty($postcode)) {
            $barcodeUrl .= '/' . $postcode;
        }

        return $barcodeUrl;
    }

    /**
     * Retrieves street name, house number and house number extension from the shipping address.
     * The shipping address may be in multiple street lines configuration or single line configuration. In the case of
     * multi-line, each part of the street data will be in a separate field. In the single line configuration, each part
     * will be in the same field and will have to be split using PREG.
     *
     * @param Mage_Customer_Model_Address_Abstract $address
     * @param null|int                             $storeId
     *
     * @return array
     */
    public function getStreetData($address, $storeId = null)
    {

        $fullStreet = $address->getStreetFull();

        if ($address->getCountry() != 'NL') {

            $fullStreet = $this->_getInternationalFullStreet($address);
            $streetData = array(
                'streetname' => $fullStreet,
                'housenumber' => '',
                'housenumberExtension' => '',
                'fullStreet' => '',
            );
            return $streetData;
        }

        if (is_null($storeId)) {
            $storeId = Mage::app()->getStore()->getId();
        }

        $splitStreet = Mage::helper('tig_myparcel/addressValidation')->useSplitStreet($storeId);

        /**
         * Website uses multi-line address mode
         */
        if ($splitStreet) {
            $streetData = $this->_getMultiLineStreetData($address);

            /**
             * If $streetData is false it means a required field was missing. In this
             * case the alternative methods are used to obtain the address data.
             */
            if ($streetData !== false) {
                return $streetData;
            }
        }


        /**
         * Split the address using PREG.
         * @var TIG_MyParcel2014_Helper_Data $this
         */
        $streetData = $this->_getSplitStreetData($fullStreet);

        return $streetData;
    }

    /**
     * Retrieves street name, house number and hous enumber extension from the shipping address in the multiple street
     * lines configuration.
     *
     * @param Mage_Sales_Model_Order_Address $address
     *
     * @return array
     */
    protected function _getMultiLineStreetData($address)
    {
        $addressHelper = Mage::helper('tig_myparcel/addressValidation');

        $streetnameField = $addressHelper->getStreetnameField();
        $housenumberField = $addressHelper->getHousenumberField();

        $streetname = $address->getStreet($streetnameField);
        $housenumber = $address->getStreet($housenumberField);
        $housenumber = trim($housenumber);

        /**
         * If street or house number fields are empty, use alternative options to obtain the address data
         */
        if (empty($streetname) || empty($housenumber)) {
            return false;
        }

        /**
         * Split the house number into a number and an extension
         */
        $splitHouseNumber = $addressHelper->useSplitHousenumber();
        if ($splitHouseNumber) {
            $housenumberExtensionField = $addressHelper->getHousenumberExtensionField();
            $housenumberExtension = $address->getStreet($housenumberExtensionField);

            /**
             * Make sure the house number is actually split.
             */
            if (!$housenumberExtension && !is_numeric($housenumber)) {
                $housenumberParts = $this->_splitHousenumber($housenumber);
                $housenumber = $housenumberParts['number'];
                $housenumberExtension = $housenumberParts['extension'];
            }
        } else {
            $housenumberParts = $this->_splitHousenumber($housenumber);
            $housenumber = $housenumberParts['number'];
            $housenumberExtension = $housenumberParts['extension'];
        }

        if (empty($housenumber)) {
            return false;
        }

        $streetData = array(
            'streetname' => $streetname,
            'housenumber' => $housenumber,
            'housenumberExtension' => $housenumberExtension,
            'fullStreet' => '',
        );

        return $streetData;
    }

    /**
     * Splits street data into separate parts for street name, housenumber and extension.
     *
     * @param string $fullStreet The full street name including all parts
     *
     * @return array
     *
     * @throws TIG_MyParcel2014_Exception
     */
    protected function _getSplitStreetData($fullStreet)
    {
        $fullStreet = preg_replace("/[\n\r]/", "", $fullStreet);

        $result = preg_match(self::SPLIT_STREET_REGEX, $fullStreet, $matches);

        if (!$result || !is_array($matches) || $fullStreet != $matches[0]) {
            if ($fullStreet != $matches[0]) {
                // Characters are gone by preg_match
                throw new TIG_MyParcel2014_Exception(
                    $this->__('Something went wrong with splitting up address %s.', $fullStreet),
                    'MYPA-0026'
                );
            } else {
                // Invalid full street supplied
                throw new TIG_MyParcel2014_Exception(
                    $this->__('Invalid full street supplied: %s.', $fullStreet),
                    'MYPA-0005'
                );
            }
        }

        $streetname = '';
        $housenumber = '';
        if (isset($matches[1])) {
            $streetname = $matches[1];
        }

        if (isset($matches[2])) {
            $housenumber = $matches[2];
        }

        $housenumberParts = $this->_splitHousenumber($housenumber);
        $housenumber = $housenumberParts['number'];
        $housenumberExtension = $housenumberParts['extension'];
        $streetData = array(
            'streetname' => $streetname,
            'housenumber' => $housenumber,
            'housenumberExtension' => $housenumberExtension,
            'fullStreet' => '',
        );

        return $streetData;
    }

    /**
     * Splits a supplier house number into a number and an extension.
     *
     * @param string $housenumber
     *
     * @return array
     *
     * @throws TIG_MyParcel2014_Exception
     */
    protected function _splitHousenumber($housenumber)
    {
        $housenumber = trim($housenumber);
        $result = preg_match(self::SPLIT_HOUSENUMBER_REGEX, $housenumber, $matches);

        if (!$result || !is_array($matches) || $housenumber != $matches[0]) {
            if ($housenumber != $matches[0]) {
                // Characters are gone by preg_match
                throw new TIG_MyParcel2014_Exception(
                    $this->__('Something went wrong with splitting up housenumber %s.', $housenumber),
                    'MYPA-0027'
                );
            } else {
                // Invalid housnumber supplied
                throw new TIG_MyParcel2014_Exception(
                    $this->__('Invalid housnumber supplied: %s.', $housenumber),
                    'MYPA-0006'
                );
            }
        }

        $extension = '';
        $number = '';
        if (isset($matches[1])) {
            $number = trim($matches[1]);
        }

        if (isset($matches[2])) {
            $extension = trim($matches[2]);
        }

        $housenumberParts = array(
            'number' => $number,
            'extension' => $extension,
        );

        return $housenumberParts;
    }

    /**
     * Generate the entire global address at two address fields
     *
     * @param Mage_Sales_Model_Order_Address $address
     *
     * @return string
     */
    protected function _getInternationalFullStreet($address)
    {
        if (empty($address->getStreet2())) {
            return preg_replace("/[\n\r]/", " ", $address->getStreetFull());
        }

        $numberStreet = ['CN', 'FR', 'GR', 'IE', 'IL', 'JP', 'LU', 'MY', 'MA', 'NZ', 'SG', 'GB',];
        if(in_array($address->getCountry(), $numberStreet)){
            return $address->getStreet2() . ' ' . $address->getStreet1();
        } else {
            return preg_replace("/[\n\r]/", " ", $address->getStreetFull());
        }
    }

    /**
     * Checks if the current edition of Magento is enterprise. Uses Mage::getEdition if available. If not, look for the
     * Enterprise_Enterprise extension. Finally, check the version number.
     *
     * @return boolean
     */
    public function isEnterprise()
    {
        /**
         * Use Mage::getEdition, which is available since CE 1.7 and EE 1.12.
         */
        if (method_exists('Mage', 'getEdition')) {
            $edition = Mage::getEdition();
            if ($edition == Mage::EDITION_ENTERPRISE) {
                return true;
            }

            return false;
        }

        /**
         * Check if the Enterprise_Enterprise extension is installed.
         */
        if (Mage::getConfig()->getNode('modules')->Enterprise_Enterprise) {
            return true;
        }

        return false;
    }

    /**
     * Checks if the current environment is in the shop's admin area.
     *
     * @return boolean
     */
    public function isAdmin()
    {
        if (Mage::app()->getStore()->isAdmin()) {
            return true;
        }

        /**
         * Fallback check in case the previous check returns a false negative.
         */
        if (Mage::getDesign()->getArea() == 'adminhtml') {
            return true;
        }

        return false;
    }

    /**
     * various checks if the extension is enabled
     *
     * @param bool $storeId
     *
     * @return bool
     */
    public function isEnabled($storeId = false)
    {
        if (!$storeId) {
            $storeId = Mage::app()->getStore()->getId();
        }

        return Mage::getStoreConfigFlag(self::XPATH_MYPARCEL_CONFIG_ACTIVE, $storeId);
    }

    /**
     * Logs a debug message. Based on Mage::log.
     *
     * @param string      $message
     * @param int|null    $level
     * @param string|null $file
     * @param boolean     $forced
     * @param boolean     $isError
     *
     * @return $this
     *
     * @see Mage::log
     */
    public function log($message, $level = null, $file = null, $forced = false, $isError = false)
    {
        if (is_null($level)) {
            $level = Zend_Log::DEBUG;
        }

        if (is_null($file)) {
            $file = self::MYPARCEL_DEBUG_LOG_FILE;
        }

        Mage::log($message, $level, $file, $forced);

        return $this;
    }

    /**
     * Logs a MyParcel Exception. Based on Mage::logException.
     *
     * @param string|Exception $exception
     *
     * @return $this
     *
     * @see Mage::logException
     */
    public function logException($exception)
    {
        if (is_object($exception)) {
            $message = "\n" . $exception->__toString();
        } else {
            $message = $exception;
        }

        $file = self::MYPARCEL_EXCEPTION_LOG_FILE;

        $this->log($message, Zend_Log::ERR, $file, false, true);

        return $this;
    }

    /**
     * Add a message to the specified session. Message can be an error, a success message, an info message or a warning.
     * If a valid error code is supplied, the message will be prepended with the error code and a link to a
     * knowledgebase article will be appended.
     *
     * If no $code is specified, $messageType and $message will be required
     *
     * @param string|Mage_Core_Model_Session_Abstract $session The session to which the messages will be added.
     * @param string|null                             $code
     * @param string|null                             $messageType
     * @param string|null                             $message
     *
     * @return $this
     *
     * @see Mage_Core_Model_Session_Abstract::addMessage()
     *
     * @throws InvalidArgumentException
     * @throws TIG_MyParcel2014_Exception
     */
    public function addSessionMessage($session, $code = null, $messageType = null, $message = null)
    {
        /***************************************************************************************************************
         * Check that the required arguments are available and valid.
         **************************************************************************************************************/

        /**
         * If $code is null or 0, $messageType and $message are required.
         */
        if (
            (is_null($code) || $code === 0)
            && (is_null($messageType) || is_null($message))
        ) {
            throw new InvalidArgumentException(
                "Warning: Missing argument for addSessionMessage method: 'messageType' and 'message' are required."
            );
        }

        /**
         * If the session is a string, treat it as a class name and instantiate it.
         */
        if (is_string($session) && strpos($session, '/') !== false) {
            $session = Mage::getSingleton($session);
        } elseif (is_string($session)) {
            $session = Mage::getSingleton($session . '/session');
        }

        /**
         * If the session could not be loaded or is not of the correct type, throw an exception.
         */
        if (!$session
            || !is_object($session)
            || !($session instanceof Mage_Core_Model_Session_Abstract)
        ) {
            throw new TIG_MyParcel2014_Exception(
                $this->__('Invalid session requested.'),
                'MYPA-0007'
            );
        }

        $errorMessage = $this->getSessionMessage($code, $messageType, $message);

        /***************************************************************************************************************
         * Add the error to the session.
         **************************************************************************************************************/

        /**
         * The method we'll use to add the message to the session has to be built first.
         */
        $addMethod = 'add' . ucfirst($messageType);

        /**
         * If the method doesn't exist, throw an exception.
         */
        if (!method_exists($session, $addMethod)) {
            throw new TIG_MyParcel2014_Exception(
                $this->__('Invalid message type requested: %s.', $messageType),
                'MYPA-0008'
            );
        }

        /**
         * Add the message to the session.
         */
        $session->$addMethod($errorMessage);

        return $this;
    }

    /**
     * Formats a message string so it can be added as a session message.
     *
     * @param null|string $code
     * @param null|string $messageType
     * @param null|string $message
     *
     * @return string
     *
     * @throws TIG_MyParcel2014_Exception
     * @throws InvalidArgumentException
     */
    public function getSessionMessage($code = null, $messageType = null, $message = null)
    {
        /**
         * If $code is null or 0, $messageType and $message are required.
         */
        if (
            (is_null($code) || $code === 0)
            && (is_null($messageType) || is_null($message))
        ) {
            throw new InvalidArgumentException(
                "Warning: Missing argument for addSessionMessage method: 'messageType' and 'message' are required."
            );
        }

        /***************************************************************************************************************
         * Get the actual error from config.xml if it's available.
         **************************************************************************************************************/

        $error = false;
        $link = false;

        if (!is_null($code) && $code !== 0) {
            /**
             * get the requested code and if possible, the knowledgebase link
             */
            $error = Mage::getConfig()->getNode('tig/errors/' . $code);
            if ($error !== false) {
                $link = (string)$error->url;
            }
        }

        /***************************************************************************************************************
         * Check that the required 'message' and 'messageType' components are available. If they are not yet available,
         * we'll try to read them from the error itself.
         **************************************************************************************************************/

        /**
         * If the specified error was found and no message was supplied, get the error's default message.
         */
        if ($error && !$message) {
            $message = (string)$error->message;
        }

        /**
         * If we still don't have a valid message, throw an exception.
         */
        if (!$message) {
            throw new TIG_MyParcel2014_Exception(
                $this->__('No message supplied.'),
                'MYPA-0009'
            );
        }

        /**
         * If the specified error was found and no message type was supplied, get the error's default type.
         */
        if ($error && !$messageType) {
            $messageType = (string)$error->type;
        }


        /**
         * If we still don't have a valid message type, throw an exception.
         */
        if (!$messageType) {
            throw new TIG_MyParcel2014_Exception(
                $this->__('No message type supplied.'),
                'MYPA-0010'
            );
        }

        /***************************************************************************************************************
         * Build the actual message we're going to add. The message will consist of the error code, followed by the
         * actual message and finally a link to the knowledge base. Only the message part is required.
         **************************************************************************************************************/

        /**
         * Lets start with the error code if it's present. It will be formatted as "[MYPARCEL-0001]".
         */
        $errorMessage = '';
        if (!is_null($code)
            && $code !== 0
        ) {
            $errorMessage .= "[{$code}] ";
        }

        /**
         * Add the actual message. This is the only required part. The code and link are optional.
         */
        $errorMessage .= $this->__($message);

        /**
         * Add the link to the knowledgebase if we have one.
         */
        if ($link) {
            $errorMessage .= ' <a href="'
                . $link
                . '" target="_blank" class="myparcel-message">'
                . $this->__('Click here for more information from the TiG knowledgebase.')
                . '</a>';
        }

        return $errorMessage;
    }

    /**
     * Adds an error message to the specified session based on an exception. The exception should contain a valid error
     * code in order to properly process the error. Exceptions without a (valid) error code will behave like a regular
     * $session->addError() call.
     *
     * @param string|Mage_Core_Model_Session_Abstract $session The session to which the messages will be added.
     * @param Exception                               $exception
     *
     * @return $this
     */
    public function addExceptionSessionMessage($session, Exception $exception)
    {
        /**
         * Get the error code, message type (hardcoded as 'error') and the message of the exception
         */
        $messageType = 'error';
        $exceptionMessage = trim($exception->getMessage());
        $message = $this->__('An error occurred while processing your request: ') . $exceptionMessage;
        $code = $exception->getCode();
        if (empty($code)) {
            $code = $this->getErrorCodeByMessage($exceptionMessage);
        }

        return $this->addSessionMessage($session, $code, $messageType, $message);
    }

    /**
     * Gets an error code by looping through all known errors and if the specified message can be matched, returning the
     * associated code.
     *
     * @param string $message
     *
     * @return string|null
     */
    public function getErrorCodeByMessage($message)
    {
        /**
         * Get an array of all known errors
         */
        $errors = Mage::getConfig()->getNode('tig/errors')->asArray();

        /**
         * Loop through each error and compare it's message
         */
        foreach ($errors as $code => $error) {
            $errorMessage = (string)$error['message'];

            /**
             * If a the error's message and the specified message match, return the error code
             */
            if (strcasecmp($message, $errorMessage) === 0) {
                return $code;
            }
        }

        return null;
    }

    /**
     * @param string                          $barcode
     * @param TIG_MyParcel2014_Model_Shipment $myParcelShipment
     *
     * @return bool
     * @throws TIG_MyParcel2014_Exception
     */
    public function sendBarcodeEmail($barcode = '', $myParcelShipment)
    {
        if (empty($barcode)) {
            return false;
        }

        if (!$myParcelShipment instanceof TIG_MyParcel2014_Model_Shipment) {
            return false;
        }

        $order = $myParcelShipment->getOrder();
        $storeId = $order->getStoreId();
        $templateId = $this->getConfig('tracktrace_template', 'general', $storeId);

        //if no template is set, return false: tracktrace should be send by MyParcel
        if ($templateId === null || $templateId == 'tig_myparcel_general_tracktrace_template') {
            return false;
        }

        try {
            // Retrieve specified view block from appropriate design package (depends on emulated store)
            $paymentBlock = Mage::helper('payment')->getInfoBlock($order->getPayment())
                ->setIsSecureMode(true);
            $paymentBlock->getMethod()->setStore($storeId);
            $paymentBlockHtml = $paymentBlock->toHtml();
        } catch (Exception $exception) {
            $paymentBlockHtml = '';
        }

        $shippingAddress = $myParcelShipment->getShippingAddress();
        $barcodeUrl = $this->getBarcodeUrl($barcode, $shippingAddress);
        $templateVariables = array(
            'tracktrace_url' => $barcodeUrl,
            'order' => $order,
            'shipment' => $myParcelShipment->getShipment(),
            'billing' => $order->getBillingAddress(),
            'payment_html' => $paymentBlockHtml,
        );

        try {
            $mailer = Mage::getModel('core/email_template_mailer');
            $emailInfo = Mage::getModel('core/email_info');
            $emailInfo->addTo($order->getCustomerEmail(), $shippingAddress->getName());

            $mailer->addEmailInfo($emailInfo);

            // Set all required params and send emails.
            $mailer->setSender(Mage::getStoreConfig(self::XML_PATH_EMAIL_IDENTITY, $storeId));
            $mailer->setStoreId($storeId);
            $mailer->setTemplateId($templateId);
            $mailer->setTemplateParams($templateVariables);

            $mailer->send();
        } catch (Exception $e) {
            $this->logException($e);
            return false;
        }

        return true;
    }

    public function hasMyParcelShipment($shipmentId)
    {
        $myParcelShipment = Mage::getModel('tig_myparcel/shipment')->load($shipmentId, 'shipment_id');

        if ($myParcelShipment->getId() != 0) {
            return true;
        }
        return false;
    }

    public function getShippingMethodConfig($shippingMethod, $setting)
    {
        $aConfig = Mage::getStoreConfig('tig_myparcel/' . $shippingMethod, Mage::app()->getStore());

        if ($aConfig[$shippingMethod . '_' . $setting] != false) {
            return $aConfig[$shippingMethod . '_' . $setting];
        } else {
            return false;
        }
    }

    /**
     * Update rate price in the checkout
     *
     * TIG_MyParcel2014_Model_Observer_SavePrice::salesQuoteCollectTotalsBefore() also ensures that the price will be adjusted at checkout
     *
     * @param Mage_Sales_Model_Quote $quote
     *
     * @throws Exception
     */
    public function updateRatePrice(Mage_Sales_Model_Quote $quote)
    {
        /**
         * @var $rate Mage_Sales_Model_Quote_Address_Rate
         */
        $shipAddress = $quote->getShippingAddress();

        foreach ($shipAddress->getShippingRatesCollection() as $rate) {
            if ($rate->getCarrier() == 'myparcel') {
                $code = $rate->getData('code');
            }
        }
        if ($code) {
            $oRate = $shipAddress->getShippingRateByCode($code);
            $oRate->setPrice($this->calculatePrice($quote));
            $oRate->save();
        }
    }

    /**
     * Get the price of the chosen options in the checkout
     *
     * @param Mage_Sales_Model_Quote $quote
     *
     * @return float
     * @throws TIG_MyParcel2014_Exception
     */
    public function calculatePrice(Mage_Sales_Model_Quote $quote)
    {

        if ($this->_isFree()) {
            $price = 0;
        } else {
            $rates = Mage::getModel('tig_myparcel/carrier_myParcel')->collectRates($quote);
            $rates = $rates->getAllRates();
            $rate = $rates[0];
            $price = (float)$rate->getData('price');
        }
        $data = json_decode($quote->getMyparcelData(), true);

        /**
         * If shipping method is delivery else shipping method is pickup
         */
        if ($data['time'][0]['price_comment'] !== null) {
            $priceComment = $data['time'][0]['price_comment'];
            if ($priceComment == 'morning') {
                $price += (float)$this->getConfig('morningdelivery_fee', 'morningdelivery');
            } elseif ($priceComment == 'avond') {
                $price += (float)$this->getConfig('eveningdelivery_fee', 'eveningdelivery');
            }
            if ($data['home_address_only'] === true)
                $price += (float)$this->getConfig('only_recipient_fee', 'delivery');

            if ($data['signed'] === true)
                $price += (float)$this->getConfig('signature_fee', 'delivery');

        } else {
            if ($data['price_comment'] == 'retail') {
                $price += (float)$this->getConfig('pickup_fee', 'pickup');
            } elseif ($data['price_comment'] == 'retailexpress') {
                $price += (float)$this->getConfig('pickup_express_fee', 'pickup_express');
            }
        }
        return $price;
    }

    private function _isFree()
    {
        $quote = Mage::getModel('checkout/cart')->getQuote();
        foreach ($quote->getItemsCollection() as $item) {
            if ($item->getData('free_shipping') == '1') {
                return true;
            }
        }
        return false;
    }
}

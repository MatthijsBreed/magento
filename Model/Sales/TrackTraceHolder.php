<?php
/**
 * An object with the track and trace data
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Model\Sales;

use Exception;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment\Track;
use MyParcelNL\Magento\Adapter\DeliveryOptionsFromOrderAdapter;
use MyParcelNL\Magento\Helper\Data;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Services\Normalizer\ConsignmentNormalizer;
use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;
use MyParcelNL\Sdk\src\Exception\MissingFieldException;
use MyParcelNL\Sdk\src\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\src\Factory\DeliveryOptionsAdapterFactory;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;
use MyParcelNL\Sdk\src\Model\Consignment\DropOffPoint;
use MyParcelNL\Sdk\src\Model\MyParcelCustomsItem;
use MyParcelNL\Sdk\src\Services\Web\RedJePakketjeDropOffPointWebService;

/**
 * Class TrackTraceHolder
 * @package MyParcelNL\Magento\Model\Sales
 */
class TrackTraceHolder
{
    /**
     * Track title showing in Magento
     */
    public const MYPARCEL_TRACK_TITLE  = 'MyParcel';
    public const MYPARCEL_CARRIER_CODE = 'myparcel';
    public const EXPORT_MODE_PPS       = 'pps';
    public const EXPORT_MODE_SHIPMENTS = 'shipments';

    private const ORDER_NUMBER  = '%order_nr%';
    private const DELIVERY_DATE = '%delivery_date%';
    private const PRODUCT_ID    = '%product_id%';
    private const PRODUCT_NAME  = '%product_name%';
    private const PRODUCT_QTY   = '%product_qty%';

    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;

    /**
     * @var \MyParcelNL\Magento\Model\Source\DefaultOptions
     */
    private static $defaultOptions;

    /**
     * @var Data
     */
    private $helper;

    /**
     * @var Order\Shipment\Track
     */
    public $mageTrack;

    /**
     * @var \MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment|null
     */
    public $consignment;

    /**
     * @var null|string
     */
    private $carrier;

    /**
     * @var mixed
     */
    private $apiKey;

    /**
     * @var null|\Magento\Sales\Model\Order\Shipment
     */
    private $shipment;

    /**
     * @var \Magento\Sales\Model\Order\Address
     */
    private $shippingAddress;

    /**
     * @var int
     */
    private $packageType;

    /**
     * @var \MyParcelNL\Sdk\src\Adapter\DeliveryOptions\AbstractDeliveryOptionsAdapter
     */
    private $deliveryOptionsAdapter;

    /**
     * @var null|float|mixed
     */
    private $checkoutData;

    /**
     * @var array
     */
    private $options;

    /**
     * TrackTraceHolder constructor.
     *
     * @param  ObjectManagerInterface      $objectManager
     * @param  Data                        $helper
     * @param  \Magento\Sales\Model\Order  $order
     */
    public function __construct(
        ObjectManagerInterface $objectManager,
        Data                   $helper,
        Order                  $order
    )
    {
        $this->objectManager  = $objectManager;
        $this->helper         = $helper;
        $this->messageManager = $this->objectManager->create('Magento\Framework\Message\ManagerInterface');
        self::$defaultOptions = new DefaultOptions(
            $order,
            $this->helper
        );
    }

    /**
     * Create Magento Track from Magento shipment
     *
     * @param  Order\Shipment  $shipment
     *
     * @return $this
     */
    public function createTrackTraceFromShipment(Order\Shipment &$shipment): self
    {
        $this->mageTrack = $this->objectManager->create('Magento\Sales\Model\Order\Shipment\Track');
        $this->mageTrack
            ->setOrderId($shipment->getOrderId())
            ->setShipment($shipment)
            ->setCarrierCode(self::MYPARCEL_CARRIER_CODE)
            ->setTitle(self::MYPARCEL_TRACK_TITLE)
            ->setQty($shipment->getTotalQty())
            ->setTrackNumber(TrackAndTrace::VALUE_EMPTY);

        return $this;
    }

    /**
     * Set all data to MyParcel object
     *
     * @param  Order\Shipment\Track  $magentoTrack
     * @param  array                 $options
     *
     * @return $this
     * @throws \Exception
     * @throws LocalizedException
     */
    public function convertDataFromMagentoToApi(Track $magentoTrack, array $options): self
    {
        $this->magentoTrack    = $magentoTrack;
        $this->shipment        = $this->magentoTrack->getShipment();
        $this->shippingAddress = $this->shipment->getShippingAddress();
        $this->checkoutData    = $this->shipment->getOrder()->getData('myparcel_delivery_options');
        $this->options         = $options;
        $deliveryOptions       = json_decode($this->checkoutData, true);

        try {
            // create new instance from known json
            $this->deliveryOptionsAdapter = DeliveryOptionsAdapterFactory::create((array)$deliveryOptions);
        } catch (\BadMethodCallException $e) {
            // create new instance from unknown json data
            $deliveryOptions              = (new ConsignmentNormalizer((array)$deliveryOptions + $options))->normalize();
            $this->deliveryOptionsAdapter = new DeliveryOptionsFromOrderAdapter($deliveryOptions);
        }

        $this->carrier     = $this->deliveryOptionsAdapter->getCarrier();
        $this->packageType = $this->getPackageType($options);
        $this->apiKey      = $this->helper->getGeneralConfig(
            'api/key',
            $this->shipment->getOrder()->getStoreId()
        );

        try {
            $this->setConsignmentData();
        } catch (\Exception $e) {
            $errorHuman = 'An error has occurred while exporting order ' . $this->shipment->getOrder()->getIncrementId() . '. ';
            $this->messageManager->addErrorMessage($errorHuman . $e->getMessage());
            $this->objectManager->get('Psr\Log\LoggerInterface')->critical($errorHuman . '-' . $e);
            $this->helper->setOrderStatus($this->magentoTrack->getOrderId(), Order::STATE_NEW);
        }

        return $this;
    }

    /**
     * @param  array  $options
     *
     * @return int
     * @throws LocalizedException
     */
    private function getPackageType(array $options): int
    {
        // get packagetype from delivery_options and use it for process directly
        $packageType = self::$defaultOptions->getPackageType();
        // get packagetype from selected radio buttons and check if package type is set
        if ($options['package_type'] && $options['package_type'] != 'default') {
            $packageType = $options['package_type'] ?? AbstractConsignment::PACKAGE_TYPE_PACKAGE;
        }

        if (! is_numeric($packageType)) {
            $packageType = AbstractConsignment::PACKAGE_TYPES_NAMES_IDS_MAP[$packageType];
        }

        return $this->getAgeCheck($this->shippingAddress) ? AbstractConsignment::PACKAGE_TYPE_PACKAGE : $packageType;
    }

    /**
     * @throws \MyParcelNL\Sdk\src\Exception\MissingFieldException
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Exception
     */
    private function setConsignmentData(): void
    {
        $this->setBaseData();
        $this->setRecipient();
        $this->setShipmentOptions();
        $this->setPickupLocation();
        $this->setDropOffPoint();
        $this->setCustomsDeclaration();
    }

    private function setBaseData(): void
    {
        $this->validateApiKey($this->apiKey);

        $this->consignment = (ConsignmentFactory::createByCarrierName($this->carrier))
            ->setApiKey($this->apiKey)
            ->setReferenceIdentifier($this->shipment->getEntityId())
            ->setConsignmentId($this->magentoTrack->getData('myparcel_consignment_id'))
            ->setCountry($this->shippingAddress->getCountryId())
            ->setCompany(self::$defaultOptions->getMaxCompanyName($this->shippingAddress->getCompany()))
            ->setPackageType($this->packageType)
            ->setDeliveryDate($this->helper->convertDeliveryDate($this->deliveryOptionsAdapter->getDate()))
            ->setDeliveryType($this->helper->checkDeliveryType($this->deliveryOptionsAdapter->getDeliveryTypeId()))
            ->setLabelDescription($this->getLabelDescription($this->checkoutData))
            ->setPerson($this->shippingAddress->getName());
    }

    private function setRecipient(): void
    {
        $this->consignment
            ->setCountry($this->shippingAddress->getCountryId())
            ->setPerson($this->shippingAddress->getName())
            ->setCompany($this->shippingAddress->getCompany())
            ->setCity($this->shippingAddress->getCity())
            ->setEmail($this->shippingAddress->getEmail())
            ->setPhone($this->shippingAddress->getTelephone())
            ->setSaveRecipientAddress(false);

        try {
            $this->consignment
                ->setFullStreet($this->shippingAddress->getData('street'))
                ->setPostalCode(preg_replace('/\s+/', '', $this->shippingAddress->getPostcode()));
        } catch (\Exception $e) {
            $errorHuman = 'An error has occurred while validating order number ' . $this->shipment->getOrder()->getIncrementId() . '. Check address.';
            $this->messageManager->addErrorMessage($errorHuman . ' View log file for more information.');
            $this->objectManager->get('Psr\Log\LoggerInterface')->critical($errorHuman . '-' . $e);

            $this->helper->setOrderStatus($this->magentoTrack->getOrderId(), Order::STATE_NEW);
        }
    }

    /**
     * @throws \MyParcelNL\Sdk\src\Exception\MissingFieldException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function setShipmentOptions(): void
    {
        $this->consignment
            ->setAgeCheck($this->getAgeCheck($this->shippingAddress))
            ->setInsurance(
                $this->options['insurance'] !== null ? $this->options['insurance'] : self::$defaultOptions->getDefaultInsurance($this->carrier)
            )
            ->setLargeFormat($this->checkLargeFormat())
            ->setOnlyRecipient($this->checkOnlyRecipient($this->options))
            ->setSignature($this->checkSignature($this->options))
            ->setReturn($this->checkReturn($this->options))
            ->setContents(AbstractConsignment::PACKAGE_CONTENTS_COMMERCIAL_GOODS)
            ->setInvoice($this->magentoTrack->getShipment()->getOrder()->getIncrementId());
    }

    private function setPickupLocation(): void
    {
        $pickupLocation = $this->deliveryOptionsAdapter->getPickupLocation();

        if (! $this->deliveryOptionsAdapter->isPickup() || ! $pickupLocation) {
            return;
        }

        $this->consignment
            ->setPickupPostalCode($pickupLocation->getPostalCode())
            ->setPickupStreet($pickupLocation->getStreet())
            ->setPickupCity($pickupLocation->getCity())
            ->setPickupNumber($pickupLocation->getNumber())
            ->setPickupCountry($pickupLocation->getCountry())
            ->setPickupLocationName($pickupLocation->getLocationName())
            ->setPickupLocationCode($pickupLocation->getLocationCode())
            ->setRetailNetworkId($pickupLocation->getRetailNetworkId());
    }

    /**
     * @throws \MyParcelNL\Sdk\src\Exception\AccountNotActiveException
     * @throws \MyParcelNL\Sdk\src\Exception\ApiException
     * @throws \MyParcelNL\Sdk\src\Exception\MissingFieldException
     */
    private function setDropOffPoint(): void
    {
        $dropOffPoints = (new RedJePakketjeDropOffPointWebService())
            ->setApiKey($this->apiKey)
            ->getDropOffPoints('2131BC');

        $dropOffPoint = (new DropOffPoint())
            ->setBoxNumber()
            ->setCc($dropOffPoints[0]['cc'] ?? null)
            ->setCity($dropOffPoints[0]['city'] ?? null)
            ->setLocationCode($dropOffPoints[0]['location_code'] ?? null)
            ->setLocationName($dropOffPoints[0]['location_name'] ?? null)
            ->setNumber($dropOffPoints[0]['number'] ?? null)
            ->setNumberSuffix($dropOffPoints[0]['number_suffix'] ?? null)
            ->setPostalCode($dropOffPoints[0]['postal_code'] ?? null)
            ->setRegion($dropOffPoints[0]['region'] ?? null)
            ->setRetailNetworkId($dropOffPoints[0]['retail_network_id'] ?? null)
            ->setState($dropOffPoints[0]['state'] ?? null)
            ->setStreet($dropOffPoints[0]['street'] ?? null);

        $this->consignment->setDropOffPoint($dropOffPoint);
    }

    /**
     * Sets a customs declaration for the consignment if necessary.
     *
     * @throws \Exception
     */
    private function setCustomsDeclaration(): void
    {
        $this->convertDataForCdCountry()
             ->calculateTotalWeight();
    }

    /**
     * @param  array  $options
     *
     * @return bool
     */
    private function checkSignature(array $options): bool
    {
        return $this->getValueOfOption($options, 'signature');
    }

    /**
     * @param  array  $options
     *
     * @return bool
     */
    private function checkOnlyRecipient(array $options): bool
    {
        return $this->getValueOfOption($options, 'only_recipient');
    }

    /**
     * @param $options
     *
     * @return bool
     */
    private function checkReturn($options): bool
    {
        return $this->getValueOfOption($options, 'return');
    }

    /**
     * @return bool
     */
    private function checkLargeFormat(): bool
    {
        return self::$defaultOptions->getDefaultLargeFormat('large_format', $this->carrier);
    }

    /**
     * @param  \Magento\Sales\Model\Order\Address  $address
     *
     * @return bool
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function getAgeCheck(Order\Address $address): bool
    {
        if ($address->getCountryId() !== AbstractConsignment::CC_NL) {
            return false;
        }

        $ageCheckOfProduct    = $this->getAgeCheckFromProduct();
        $ageCheckFromSettings = self::$defaultOptions->getDefaultOptionsWithoutPrice('age_check', $this->carrier);

        return $ageCheckOfProduct ?? $ageCheckFromSettings;
    }

    /**
     * @return bool
     * @throws LocalizedException
     */
    private function getAgeCheckFromProduct(): ?bool
    {
        $products    = $this->magentoTrack->getShipment()->getItems();
        $hasAgeCheck = false;

        foreach ($products as $product) {
            $productAgeCheck = $this->getAttributeValue('catalog_product_entity_varchar', $product['product_id'], 'age_check');

            if (! isset($productAgeCheck)) {
                $hasAgeCheck = null;
            } elseif ($productAgeCheck) {
                return true;
            }
        }

        return $hasAgeCheck;
    }

    /**
     * Override to check if key isset
     *
     * @param  string  $apiKey
     *
     * @return $this
     * @throws LocalizedException
     */
    public function validateApiKey(string $apiKey): self
    {
        if ($apiKey == null) {
            throw new LocalizedException(__('API key is not known. Go to the settings in the backoffice to create an API key. Fill the API key in the settings.'));
        }

        return $this;
    }

    /**
     * @param  string|null  $checkoutData
     *
     * @return string
     * @throws LocalizedException
     */
    public function getLabelDescription(?string $checkoutData): string
    {
        $order = $this->magentoTrack->getShipment()->getOrder();

        $labelDescription = $this->helper->getGeneralConfig(
            'print/label_description',
            $order->getStoreId()
        );

        if (! $labelDescription) {
            return '';
        }
        $productInfo      = $this->getItemsCollectionByShipmentId($this->magentoTrack->getShipment()->getId());
        $deliveryDate     = date('d-m-Y', strtotime($this->helper->convertDeliveryDate($checkoutData)));
        $labelDescription = str_replace(
            [
                self::ORDER_NUMBER,
                self::DELIVERY_DATE,
                self::PRODUCT_ID,
                self::PRODUCT_NAME,
                self::PRODUCT_QTY
            ],
            [
                $order->getIncrementId(),
                $this->helper->convertDeliveryDate($checkoutData) ? $deliveryDate : '',
                $this->getProductInfo($productInfo, 'product_id'),
                $this->getProductInfo($productInfo, 'name'),
                round($this->getProductInfo($productInfo, 'qty'), 0),
            ],
            $labelDescription
        );

        return (string) $labelDescription;
    }

    /**
     * @param $productInfo
     * @param $field
     *
     * @return string|null
     */
    private function getProductInfo(array $productInfo, string $field): ?string
    {
        if ($productInfo) {
            return $productInfo[0][$field];
        }

        return null;
    }

    /**
     * @return $this
     *
     * @throws LocalizedException
     * @throws MissingFieldException
     * @throws Exception
     */
    private function convertDataForCdCountry(): self
    {
        if (! $this->consignment->isCdCountry()) {
            return $this;
        }

        foreach ($this->magentoTrack->getShipment()->getItems() as $item) {

            $myParcelProduct = (new MyParcelCustomsItem())
                ->setDescription($item->getName())
                ->setAmount($item->getQty())
                ->setWeight($this->helper->getWeightTypeOfOption($item->getWeight() * $item->getQty()))
                ->setItemValue($this->getCentsByPrice($item->getPrice()))
                ->setClassification((int)$this->getAttributeValue('catalog_product_entity_int', $item->getProductId(), 'classification'))
                ->setCountry($this->getCountryOfOrigin($item->getProductId()));

            $this->consignment->addItem($myParcelProduct);
        }

        return $this;
    }

    /**
     * Get country of origin from product settings or, if they are not found, from the MyParcel settings.
     *
     * @param  int  $product_id
     *
     * @return string
     */
    public function getCountryOfOrigin(int $product_id): string
    {
        $product                     = $this->objectManager->get('Magento\Catalog\Api\ProductRepositoryInterface')->getById($product_id);
        $productCountryOfManufacture = $product->getCountryOfManufacture();

        if ($productCountryOfManufacture) {
            return $productCountryOfManufacture;
        }

        return $this->helper->getGeneralConfig('print/country_of_origin');
    }

    /**
     * @param  string  $tableName
     * @param  string  $entityId
     * @param  string  $column
     *
     * @return string|null
     */
    private function getAttributeValue(string $tableName, string $entityId, string $column): ?string
    {
        $objectManager = ObjectManager::getInstance();
        $resource      = $objectManager->get('Magento\Framework\App\ResourceConnection');
        $connection    = $resource->getConnection();
        $attributeId   = $this->getAttributeId(
            $connection,
            $resource->getTableName('eav_attribute'),
            $column
        );

        $attributeValue = $this
            ->getValueFromAttribute(
                $connection,
                $resource->getTableName($tableName),
                $attributeId,
                $entityId
            );

        return $attributeValue;
    }

    /**
     * @param  object  $connection
     * @param  string  $tableName
     * @param  string  $databaseColumn
     *
     * @return mixed
     */
    private function getAttributeId($connection, string $tableName, string $databaseColumn): string
    {
        $sql = $connection
            ->select('entity_type_id')
            ->from($tableName)
            ->where('attribute_code = ?', 'myparcel_' . $databaseColumn);

        return $connection->fetchOne($sql);
    }

    /**
     * @param  object  $connection
     * @param  string  $tableName
     *
     * @param  string  $attributeId
     * @param  string  $entityId
     *
     * @return string|null
     */
    private function getValueFromAttribute($connection, string $tableName, string $attributeId, string $entityId): ?string
    {
        $sql = $connection
            ->select()
            ->from($tableName, ['value'])
            ->where('attribute_id = ?', $attributeId)
            ->where('entity_id = ?', $entityId);

        return $connection->fetchOne($sql);
    }

    /**
     * Get default value if option === null
     *
     * @param  array   $options
     * @param  string  $optionKey
     *
     * @return bool
     * @internal param $option
     */
    private function getValueOfOption(array $options, string $optionKey): bool
    {
        if ($options[$optionKey] === null) {
            return (bool)self::$defaultOptions->getDefault($optionKey, $this->carrier);
        }

        return (bool)$options[$optionKey];
    }

    /**
     * @param  int  $shipmentId
     *
     * @return array
     */
    private function getItemsCollectionByShipmentId(int $shipmentId)
    {
        /** @var \Magento\Framework\App\ResourceConnection $connection */
        $connection = $this->objectManager->create('\Magento\Framework\App\ResourceConnection');
        $conn       = $connection->getConnection();
        $select     = $conn->select()
                           ->from(
                               ['main_table' => $connection->getTableName('sales_shipment_item')]
                           )
                           ->where('main_table.parent_id=?', $shipmentId);
        $items      = $conn->fetchAll($select);

        return $items;
    }

    /**
     * @return TrackTraceHolder
     *
     * @throws LocalizedException
     * @throws \Exception
     */
    private function calculateTotalWeight(): self
    {
        $totalWeight = $this->options['digital_stamp_weight'] !== null ? (int)$this->options['digital_stamp_weight'] : (int)self::$defaultOptions->getDigitalStampDefaultWeight();
        if ($this->consignment->getPackageType() !== AbstractConsignment::PACKAGE_TYPE_DIGITAL_STAMP) {
            return $this;
        }

        if ($totalWeight > 0) {
            $this->consignment->setPhysicalProperties(["weight" => $totalWeight]);

            return $this;
        }

        $weightFromSettings = (int)self::$defaultOptions->getDigitalStampDefaultWeight();
        if ($weightFromSettings) {
            $this->consignment->setPhysicalProperties(["weight" => $weightFromSettings]);

            return $this;
        }

        if ($products = $this->magentoTrack->getShipment()->getData('items')) {
            foreach ($products as $product) {
                $totalWeight += $product->consignment->getWeight();
            }
        }

        $products = $this->getItemsCollectionByShipmentId($this->magentoTrack->getShipment()->getId());

        foreach ($products as $product) {
            $totalWeight += $product['weight'];
        }

        if ($totalWeight == 0) {
            throw new Exception('The order with digital stamp can not be exported, no weights have been entered');
        }

        $this->consignment->setPhysicalProperties([
            "weight" => $totalWeight
        ]);

        return $this;
    }

    /**
     * @param  float  $price
     *
     * @return int
     */
    private function getCentsByPrice(float $price): int
    {
        return (int)$price * 100;
    }
}

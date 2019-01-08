<?php

/**
 * An extension which can be applied to either the shop {@link Product} or
 * {@link ProductVariation} class for including stock values in the CMS.
 *
 * Stock is held within a {@link ProductWarehouse}.
 *
 * @package silvershop-stock
 */
class ProductStockExtension extends DataExtension
{

    private static $db = array(
        'UnlimitedStock' => 'Boolean',
        'AllowOutOffStockPurchase' => 'Boolean'
    );

    private static $defaults = array(
        'UnlimitedStock' => 0
    );

    private static $allow_out_of_stock_purchase = false;

    public function updateCMSFields(FieldList $fields)
    {
        if ($this->hasVariations()) {
            // it has variations so then we leave the management of the stock
            // level to the variation.
            $fields->addFieldToTab('Root.Stock', new LiteralField('StockManagedVariations',
                '<p>You have variations attached to this product. To manage the stock level ' .
                'click the Stock tab on each of the variations</p>'
            ));

            return $fields;
        }

        $grid = new GridField(
            'StockLevels',
            'Stock',
            $this->getStockForEachWarehouse(),
            GridFieldConfig::create()
                ->addComponent(new GridFieldButtonRow('before'))
                ->addComponent(new GridFieldToolbarHeader())
                ->addComponent(new GridFieldEditableColumns())
                ->addComponent(new GridFieldProductStockFields())
        );

        $grid->getConfig()->getComponentByType('GridFieldEditableColumns')->setDisplayFields(array(
            'Title'    => array(
                'field' => 'ReadonlyField'
            ),
            'Quantity' => function ($record, $column, $grid){
                // Numeric doesn't support null type
                // return new NumericField($column);
                return new TextField($column);
            }
        ));

        $unlimited = new CheckboxField('UnlimitedStock', 'Unlimited Stock', 'StockLevels');
        $OutOffStock = new CheckboxField('AllowOutOffStockPurchase', 'Allow Out Off Stock Purchase');

        // if the record has a root tab, (page) otherwise it could be a
        // dataobject so we'll just
        if ($fields->fieldByName('Root')) {
            $fields->addFieldToTab('Root.Stock', $unlimited);
            $fields->addFieldToTab('Root.Stock', $OutOffStock);
            $fields->addFieldToTab('Root.Stock', $grid);
        }else {
            $fields->push($unlimited);
            $fields->push($OutOffStock);
            $fields->push($grid);
        }
    }

    /**
     * Returns a list of all the warehouses with a value in use for the stock
     * GridField instance. Will create records for products that don't have
     * them.
     *
     * @return DataList
     */
    public function getStockForEachWarehouse()
    {
        $warehouses = ProductWarehouse::get();
        $output = new ArrayList();

        foreach ($warehouses as $warehouse) {
            $stock = $this->getStockForWarehouse($warehouse);

            $output->push($stock);
        }

        return $output;
    }


    /**
     * Returns the ProductWarehouseStock for this product goven a specific warehosue.
     * IT will create a ProductWarehouseStock record for the product in the warehouse if not found.
     *
     * @return ProductWarehouseStock
     */
    public function getStockForWarehouse($warehouse)
    {
        $record = $warehouse->StockedProducts()->filter(array(
            'ProductID'    => $this->owner->ID,
            'ProductClass' => $this->owner->ClassName
        ))->first();

        $defaults = ProductWarehouseStock::config()->get('defaults');

        if (!$record) {
            $record = Injector::inst()->create('ProductWarehouseStock');
            $record->WarehouseID = $warehouse->ID;
            $record->ProductID = $this->owner->ID;
            $record->ProductClass = $this->owner->ClassName;
            $record->Quantity = 0;

            foreach ($defaults as $field => $val) {
                $record->{$field} = $val;
            }

            $record->write();
        }

        return $record;
    }


    /**
     * @param int
     *
     * @return boolean
     */
    public function hasAvailableStock($require = 1)
    {
        if ($this->hasVariations()) {
            foreach ($this->owner->Variations() as $variation) {
                if ($variation->hasAvailableStock($require)) {
                    return true;
                }
            }
        }

        if ($this->owner->UnlimitedStock) {
            return true;
        }

        if ($this->owner->AllowOutOffStockPurchase) {
            return true;
        }

        $stock = $this->getWarehouseStockQuantity();
        $pending = $this->getReservedStock();

        return ($stock - $pending) >= $require;

    }

    /**
     * Returns the number of items that are currently on hold in the shopsystem.
     *
     * @return int
     */
    public function getReservedStock()
    {
        $current = ShoppingCart::curr();

        $identifier = $this->getStockBaseIdentifier();

        $orderItem = $identifier::config()->order_item;

        $tableList = DB::table_list();

        if(!in_array($orderItem,$tableList)){
            $orderItem = 'Product_OrderItem';
        }

        $orderItemHasOne = $orderItem::config()->has_one;
        $itemIdentifier = array_shift($orderItemHasOne);

        $statuses = array('Cart');

        $this->owner->extend('updateOrderStatusArray',$statuses);

        $pending = OrderItem::get()
            ->leftJoin('Order',"\"Order\".\"ID\" = \"OrderAttribute\".\"OrderID\"")
            ->leftJoin($orderItem,"\"{$orderItem}\".\"ID\" = \"OrderItem\".\"ID\"")
            ->where("\"{$orderItem}\".\"{$itemIdentifier}ID\" = ". $this->owner->ID)
            ->where('"Status" IN (\''.implode("','",$statuses).'\')');

        if($current){
            $pending->filter('Order.ID:not',$current->ID);
        }

        return  $pending->sum('Quantity');
    }

    /**
     * Returns whether a warehouse has unlimited stock for this product
     *
     * @return boolean
     */
    public function hasWarehouseWithUnlimitedStock()
    {
        return ($this->getWarehouseStock()->where("\"Quantity\" = -1")->count() > 0);
    }


    /**
     * @return DataList
     */
    public function getWarehouseStock()
    {
        return ProductWarehouseStock::get()->filter(array(
            'ProductID'    => $this->owner->ID,
            'ProductClass' => $this->getStockBaseIdentifier()
        ));
    }

    /**
     * Returns the number of available stock. Note this cannot be used to
     * determine if stock is available as a warehouse may have an unlimited
     * (null) value for stock.
     *
     * @return boolean
     */
    public function getWarehouseStockQuantity()
    {
        return $this->getWarehouseStock()->sum('Quantity');
    }

    /**
     * @return boolean
     */
    public function canPurchase($member = null, $quantity = 1)
    {
        if ($this->owner->UnlimitedStock) {
            return true;
        }

        if ($this->getWarehouseStock()->count() < 1) {
            // no warehouses available.
            return true;
        }

        if ($this->hasVariations()) {
            // then just return. canPurchase will be called on those individual
            // variations, not the main product.
            return true;
        }

        if (Config::inst()->get('ProductStockExtension', 'allow_out_of_stock_purchase')) {
            return true;
        }

        if($this->owner->AllowOutOffStockPurchase){
            return true;
        }

        // validate to the amount they want to purchase.
        if (!$this->hasAvailableStock($quantity)) {
            return false;
        }

        return true;
    }

    /**
     * As stock can either be managed on a product or a product variation level,
     * return whether this object has variations enabled.
     *
     * @return boolean
     */
    private function hasVariations()
    {
        return (
            is_string($this->owner->has_many('Variations')) &&
            $this->owner->Variations()->exists()
        );
    }

    /**
     * @todo
     * @return string
     */
    public function getStockBaseIdentifier()
    {
        return ($this->owner instanceof SiteTree) ? $this->owner->getClassName() : "ProductVariation";
    }


    /**
     * Decrements the stock for a given order item. Potentially will reduce the
     * stock across multiple warehouses. If any of the warehouses have unlimited
     * stock, they're used a fallback.
     *
     * @param OrderItem $orderItem
     * @return
     */
    public function decrementStock(OrderItem $orderItem)
    {
        if ($orderItem->Product()->UnlimitedStock) {
            //Unlimited stock
            return;
        }

        $quantity = $orderItem->Quantity;

        foreach ($this->getWarehouseStock() as $warehouse) {
            if ($warehouse->Quantity == "-1") {
                // unlimited
                break;
            }

            if ($quantity <= $warehouse->Quantity) {
                $warehouse->Quantity -= $quantity;
            }else {
                $quantity -= $warehouse->Quantity;
                $warehouse->Quantity = 0;
            }

            $warehouse->write();
        }
    }
}

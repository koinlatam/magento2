<?php

namespace Koin\Payment\Api\Data;

interface InstallmentsRulesSearchResultsInterface extends \Magento\Framework\Api\SearchResultsInterface
{
    /**
     * Get items.
     *
     * @return \Koin\Payment\Api\Data\InstallmentsRulesInterface[]|null
     */
    public function getItems();

    /**
     * Set items.
     * @param \Koin\Payment\Api\Data\InstallmentsRulesInterface[] $items
     * @return $this
     */
    public function setItems(?array $items = null);

}

<?php
/**
 * @package plugins.elasticSearch
 * @subpackage model.items
 */
class ESearchMetadataItem extends ESearchItem
{

	private static $allowed_search_types_for_field = array(
		'metadata.value_text' => array('ESearchItemType::EXACT_MATCH'=> ESearchItemType::EXACT_MATCH, 'ESearchItemType::PARTIAL'=> ESearchItemType::PARTIAL, "ESearchItemType::DOESNT_CONTAIN"=> ESearchItemType::DOESNT_CONTAIN, 'ESearchItemType::STARTS_WITH'=> ESearchItemType::STARTS_WITH,ESearchUnifiedItem::UNIFIED),
		'metadata.value_int' => array('ESearchItemType::EXACT_MATCH'=> ESearchItemType::EXACT_MATCH, "ESearchItemType::DOESNT_CONTAIN"=> ESearchItemType::DOESNT_CONTAIN, 'ESearchItemType::RANGE'=>ESearchItemType::RANGE, ESearchUnifiedItem::UNIFIED),
	);

	/**
	 * @var string
	 */
	protected $searchTerm;

	/**
	 * @var string
	 */
	protected $xpath;

	/**
	 * @var int
	 */
	protected $metadataProfileId;

	/**
	 * @return string
	 */
	public function getSearchTerm()
	{
		return $this->searchTerm;
	}

	/**
	 * @param string $searchTerm
	 */
	public function setSearchTerm($searchTerm)
	{
		$this->searchTerm = $searchTerm;
	}

	public function getType()
	{
		return 'metadata';
	}

	/**
	 * @return string
	 */
	public function getXpath()
	{
		return $this->xpath;
	}

	/**
	 * @param string $xpath
	 */
	public function setXpath($xpath)
	{
		$this->xpath = $xpath;
	}

	/**
	 * @return int
	 */
	public function getMetadataProfileId()
	{
		return $this->metadataProfileId;
	}

	/**
	 * @param int $metadataProfileId
	 */
	public function setMetadataProfileId($metadataProfileId)
	{
		$this->metadataProfileId = $metadataProfileId;
	}
	
	public static function getAllowedSearchTypesForField()
	{
		return array_merge(self::$allowed_search_types_for_field, parent::getAllowedSearchTypesForField());
	}

	public static function createSearchQuery(array $eSearchItemsArr, $boolOperator, $eSearchOperatorType = null)
	{
		$metadataQuery['nested']['path'] = 'metadata';
		$metadataQuery['nested']['inner_hits'] = array('size' => 10, '_source' => true);
		$allowedSearchTypes = ESearchMetadataItem::getAllowedSearchTypesForField();
		foreach ($eSearchItemsArr as $metadataESearchItem)
		{
			/* @var ESearchMetadataItem $metadataESearchItem */
			self::createSingleItemSearchQuery($metadataESearchItem, $boolOperator, $metadataQuery, $allowedSearchTypes);
			if ($metadataESearchItem->getXpath())
			{
				$metadataQuery['nested']['query']['bool']['must'][] = array(
					'term' => array(
						'metadata.xpath' => $metadataESearchItem->getXpath()
					)
				);
			}
			if ($metadataESearchItem->getMetadataProfileId())
			{
				$metadataQuery['nested']['query']['bool']['must'][] = array(
					'term' => array(
						'metadata.metadata_profile_id' => $metadataESearchItem->getMetadataProfileId()
					)
				);
				return $metadataQuery;
			}
		}
		return array($metadataQuery);
	}

	public static function createSingleItemSearchQuery($metadataESearchItem, $boolOperator, &$metadataQuery, $allowedSearchTypes)
	{
		switch ($metadataESearchItem->getItemType())
		{
			case ESearchItemType::EXACT_MATCH:
				$metadataQuery['nested']['query']['bool'][$boolOperator][] =
					self::getMetadataExactMatchQuery($metadataESearchItem, $allowedSearchTypes);
				break;
			case ESearchItemType::PARTIAL:
				$metadataQuery['nested']['query']['bool'][$boolOperator][] =
					self::getMetadataMultiMatchQuery($metadataESearchItem);
				break;
			case ESearchItemType::STARTS_WITH:
				$metadataQuery['nested']['query']['bool'][$boolOperator][] =
					self::getMetadataPrefixQuery($metadataESearchItem, $allowedSearchTypes);
				break;
			case ESearchItemType::DOESNT_CONTAIN:
				$metadataQuery['nested']['query']['bool']['must_not'][] =
					self::getMetadataDoesntContainQuery($metadataESearchItem, $allowedSearchTypes);
				break;
			case ESearchItemType::RANGE:
				$metadataQuery['nested']['query']['bool'][$boolOperator][] =
					self::getMetadataRangeQuery($metadataESearchItem, $allowedSearchTypes);
		}

		if($boolOperator == 'should')
			$metadataQuery['nested']['query']['bool']['minimum_should_match'] = 1;

	}

	protected static function getMetadataExactMatchQuery($searchItem, $allowedSearchTypes)
	{
		$metadataExactMatch = array();
		if(ctype_digit($searchItem->getSearchTerm()))
		{
			$metadataExactMatch['bool']['should'][] = kESearchQueryManager::getExactMatchQuery($searchItem, 'metadata.value_text', $allowedSearchTypes);
			$metadataExactMatch['bool']['should'][] = kESearchQueryManager::getExactMatchQuery($searchItem, 'metadata.value_int', $allowedSearchTypes);
			$metadataExactMatch['bool']['minimum_should_match'] = 1;
		}
		else
			$metadataExactMatch = kESearchQueryManager::getExactMatchQuery($searchItem, 'metadata.value_text', $allowedSearchTypes);

		return $metadataExactMatch;
	}

	protected static function getMetadataMultiMatchQuery($searchItem)
	{
		$metadataMultiMatch = kESearchQueryManager::getMultiMatchQuery($searchItem, 'metadata.value_text', false);

		if(ctype_digit($searchItem->getSearchTerm()))//add metadata.value_int
			$metadataMultiMatch['multi_match']['fields'][] = 'metadata.value_int^3';

		return $metadataMultiMatch;
	}

	protected static function getMetadataPrefixQuery($searchItem, $allowedSearchTypes)
	{
		return kESearchQueryManager::getPrefixQuery($searchItem, 'metadata.value_text', $allowedSearchTypes);
	}

	protected static function getMetadataDoesntContainQuery($searchItem, $allowedSearchTypes)
	{
		$metadataDoesntContain = array();
		if(ctype_digit($searchItem->getSearchTerm()))
		{
			$metadataDoesntContain['bool']['should'][]['bool']['must_not'][] = kESearchQueryManager::getDoesntContainQuery($searchItem, 'metadata.value_text', $allowedSearchTypes);
			$metadataDoesntContain['bool']['should'][]['bool']['must_not'][] = kESearchQueryManager::getDoesntContainQuery($searchItem, 'metadata.value_int', $allowedSearchTypes);
			$metadataDoesntContain['bool']['minimum_should_match'] = 1;
		}
		else
			$metadataDoesntContain['bool']['must_not'][] = kESearchQueryManager::getDoesntContainQuery($searchItem, 'metadata.value_text', $allowedSearchTypes);

		return $metadataDoesntContain;
	}
	
	protected static function getMetadataRangeQuery($searchItem, $allowedSearchTypes)
	{
		return kESearchQueryManager::getRangeQuery($searchItem, 'metadata.value_int', $allowedSearchTypes);
	}


}
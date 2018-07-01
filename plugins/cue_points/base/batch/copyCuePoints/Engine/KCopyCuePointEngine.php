<?php
/**
 * @package plugins.cuePoints
 * @subpackage Scheduler
 */
abstract class KCopyCuePointEngine
{
    const MAX_CUE_POINT_CHUNKS = 500;

    protected $data = null;
    protected $partnerId = null;
    private $lastCuePointPerType = null;

    abstract public function copyCuePoints();

    protected function shouldCopyCuePoint($cuePoint) {return true;}

    protected function calculateCuePointTimes($cuePoint) {return array($cuePoint->startTime, $cuePoint->endTime);}

    protected function validateJobData() {return true;}

    protected function getOrderByField() {return 'startTime';}

    protected static function postProcessCuePoints($copiedCuePointIds) {}

    protected function preProcessCuePoints(&$cuePoints)
    {
        $this->setCalculatedEndTimeOnCuePoints($cuePoints);
    }

    protected function copyCuePointsToEntry($srcEntryId, $destEntryId)
    {
	    $this->lastCuePointPerType  = array();
	    $filter = $this->getCuePointFilter($srcEntryId);
        $pager = $this->getCuePointPager();
        $clonedCuePointIds = array();
        do 
        {
            KalturaLog::debug("Getting list of cue point for entry [$srcEntryId] with pager index: " . $pager->pageIndex);
            $listResponse = KBatchBase::tryExecuteApiCall(array('KCopyCuePointEngine','cuePointList'), array($filter, $pager));
            if (!$listResponse)
                return false;
            $cuePoints = $listResponse->objects;
            $this->preProcessCuePoints($cuePoints);
            KalturaLog::debug("Return " . count($cuePoints) . " cue-points from list");
            foreach ($cuePoints as &$cuePoint)
            {
                if ($this->shouldCopyCuePoint($cuePoint))
                {
                    $clonedCuePointId = $this->copySingleCuePoint($cuePoint, $destEntryId);
                    if ($clonedCuePointId)
                        $clonedCuePointIds[] = $clonedCuePointId;
                }
            }
            $pager->pageIndex++;
            
        } while (count($cuePoints) == self::MAX_CUE_POINT_CHUNKS);
        $this->postProcessCuePoints($clonedCuePointIds);
        return true;
    }

    protected function copySingleCuePoint($cuePoint, $destEntryId)
    {
        $clonedCuePoint = KBatchBase::tryExecuteApiCall(array('KCopyCuePointEngine','cuePointClone'), array($cuePoint->id, $destEntryId));
        if ($clonedCuePoint)
        {
            list($startTime, $endTime) = $this->calculateCuePointTimes($cuePoint);
            $res = KBatchBase::tryExecuteApiCall(array('KCopyCuePointEngine','updateCuePointTimes'), array($clonedCuePoint->id, $startTime, $endTime));
            if ($res)
                return $cuePoint->id;
            else
                KalturaLog::info("Update time for [{$cuePoint->id}] of [$startTime, $endTime] - Failed");
        } else
            KalturaLog::info("Could not copy [{$cuePoint->id}] - moving to next");
        return null;
    }


    public function setData($data, $partnerId)
    {
        $this->data = $data;
        $this->partnerId = $partnerId;
    }
    
    public static function initEngine($copyCuePointJobType, $data, $partnerId)
    {
        $engine = self::getEngine($copyCuePointJobType);
        if (!$engine)
            return null;
        $engine->setData($data, $partnerId);
        return $engine;
    }

    private static function getEngine($copyCuePointJobType) {
        switch($copyCuePointJobType)
        {
            case CopyCuePointJobType::MULTI_CLIP:
               return new KMultiClipCopyCuePointEngine();
            case CopyCuePointJobType::LIVE:
                return new KLiveToVodCopyCuePointEngine();
            case CopyCuePointJobType::LIVE_CLIPPING:
                return new KLiveClippingCopyCuePointEngine();
            default:
                return null;
        }
    }

    protected function getCuePointFilter($entryId, $status = CuePointStatus::READY)
    {
        $filter = new KalturaCuePointFilter();
        $filter->entryIdEqual = $entryId;
        $filter->statusIn = $status;
        $filter->orderBy = '+' . $this->getOrderByField();
        return $filter;
    }

    protected function getCuePointPager()
    {
        $pager = new KalturaFilterPager();
        $pager->pageIndex = 0;
        $pager->pageSize = self::MAX_CUE_POINT_CHUNKS;
        return $pager;
    }

    public static function updateCuePointTimes($cuePointId, $startTime, $endTime = null)
    {
        return KBatchBase::$kClient->cuePoint->updateCuePointsTimes($cuePointId, $startTime,$endTime);
    }

    public static function cuePointList($filter, $pager)
    {
        return KBatchBase::$kClient->cuePoint->listAction($filter, $pager);
    }

    public static function cuePointClone($cuePointId, $destinationEntryId)
    {
        return KBatchBase::$kClient->cuePoint->cloneAction($cuePointId, $destinationEntryId);
    }

    public static function cuePointUpdateStatus($cuePointId, $newStatus)
    {
        return KBatchBase::$kClient->cuePoint->updateStatus($cuePointId, $newStatus);
    }

	public static function deleteCuePoint($cuePointId)
	{
		return KBatchBase::$kClient->cuePoint->delete($cuePointId);
	}


	/**
	 * @param KalturaCuePoint $currentCuePoint
	 * @param KalturaCuePoint $nextCuePoint
	 * @return mixed
	 */
	public static function updateTimesAndDeleteNextCuePoint($currentCuePoint, $nextCuePoint)
	{
		KBatchBase::$kClient->startMultiRequest();
		if (property_exists($nextCuePoint,'endTime'))
			/** @noinspection PhpUndefinedFieldInspection */
			self::updateCuePointTimes($currentCuePoint->id,$currentCuePoint->startTime,$nextCuePoint->endTime);
		self::deleteCuePoint($nextCuePoint->id);
		return KBatchBase::$kClient->doMultiRequest();
	}


    protected function setCalculatedEndTimeOnCuePoints(&$cuePoints)
    {
        //setting calculatedEndTime on cue points in order to decide in shouldCopyCuePoint method
        $orderField = $this->getOrderByField();
        foreach ($cuePoints as &$cuePoint)
        {
            // set on calculatedEndTime the end time if existed or the next cue point start time
            $type = self::getTypeName($cuePoint);
            $cuePoint->calculatedEndTime = self::getEndTimeIfExist($cuePoint);
            /** we will only Override the calculated end time if cue point does not have end time of its own, if
             * If cue Point has end time meaning it was set by user and we will continue with the times required by the user.
             */
            if (array_key_exists($type, $this->lastCuePointPerType))
            {
                //$calculatedEndTime = self::getEndTimeIfExist($this->lastCuePointPerType[$type]);
                if (!$this->lastCuePointPerType[$type]->calculatedEndTime)
                	$this->lastCuePointPerType[$type]->calculatedEndTime = $cuePoint->$orderField;
            }
            $this->lastCuePointPerType[$type] = &$cuePoint;
        }
    }

    private static function getEndTimeIfExist($cuePoint)
    {
        if (property_exists($cuePoint, 'endTime'))
            return $cuePoint->endTime;
        return null;
    }

	protected static function getCalculatedEndTimeIfExist($cuePoint)
	{
		if (property_exists($cuePoint, 'calculatedEndTime') && $cuePoint->calculatedEndTime > 0)
			return $cuePoint->calculatedEndTime;
		return self::getEndTimeIfExist($cuePoint);
	}

    private static function getTypeName($cuePoint) {
        $name = $cuePoint->cuePointType;
        if ($name == 'codeCuePoint.Code' && $cuePoint->tags == 'change-view-mode')
            $name .= '_changeViewMode';
        return $name;
    }
    
}

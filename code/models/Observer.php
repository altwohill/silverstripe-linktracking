<?php
/**
 * Extend a dataobject with functions to report on TrackedLink hits
 * 
 * Any object that wishes to be linked to all sources of a link should be decorated
 * by Observer, by putting <code>Object::add_extension('MyObject', 'Observer');</code>
 * in your _config.php file
 * 
 * @package linktracking
 * @author Al Twohill <firstname@hol.net.nz>
 **/
class Observer extends DataObjectDecorator {
	
	/**
	 * Count the number of unique hits accross any of the tracked links.
	 * A hit is unique if the Source AND IP isn't the same as any others
	 * 
	 * @return int
	 */ 
	public function getUniqueHits($where = null) {
		$query = new SQLQuery();
		$query->select(array('Count(DISTINCT `LinkHit`.`IP`, `TrackedLink`.`SourceID`, `TrackedLink`.`SourceType`)'));
		$query->from('LinkHit');
		$query->innerJoin('TrackedLink', '`LinkHit`.`LinkID` = `TrackedLink`.`ID`');
		$query->where(array(
			"`TrackedLink`.`ObserverType` = '{$this->owner->ClassName}'",
			"`TrackedLink`.`ObserverID` = {$this->owner->ID}"
		));
		if ($where) {
			$query->where(array($where));
		}
		$query->useConjunction();
		
		return $query->execute()->value();
	}
	
	/**
	 * Gets all the tracked links being observed by this object
	 * 
	 * @return DataObjectSet
	 */
	public function getLinks() {
		$sql_type = Convert::raw2sql($this->owner->ClassName);
		$sql_id = (int) $this->owner->ID;
		return DataObject::get('TrackedLink', "`ObserverType` = '$sql_type' AND `ObserverID` = '$sql_id'");
	}
	
	/**
	 * Return all the clicks (redirects) counted
	 * 
	 * @return DataObjectSet
	 */
	public function getClicks($where = null) {
		$query = new SQLQuery();
		$query->select(array(
			'`LinkHit`.`ID`',
			'`LinkHit`.`IP`',
			'`LinkHit`.`Created`',
			'`LinkHit`.`LinkID`',
			'\'LinkHit\' AS `RecordClassName`'));
		$query->from('LinkHit');
		$query->innerJoin('TrackedLink', '`LinkHit`.`LinkID` = `TrackedLink`.`ID`');
		$query->where(array(
			"`TrackedLink`.`ObserverType` = '{$this->owner->ClassName}'",
			"`TrackedLink`.`ObserverID` = {$this->owner->ID}",
			"`TrackedLink`.`LinkType` = 'Redirect'"
		));
		if ($where) {
			$query->where(array($where));
		}
		$query->useConjunction();
		$result = $query->execute();
		return singleton('LinkHit')->buildDataObjectSet($result);
	}
	
	/**
	 * Returns a DataObjectSet of ArrayData of stats for particular times.
	 * Useful for creating graphs similar to Google Analytics
	 * 
	 * @param SS_Datetime $start the start time
	 * @param SS_Datetime $end the end time
	 * @param int increment, in seconds
	 * 
	 * @return DataObjectSet of ArrayData(
	 * 		'Datetime' => SS_Datetime, The start of the time period
	 * 		'Hits' => Int, The number of unique hits *within this time period*
	 * 		'Clicks' => Int The number of links clicked within this time period
	 * )
	 */
	public function activityOverTime($start, $end, $increment) {
		$set = new DataObjectSet();
		
		while ($start->Value <= $end->Value) {
			$date = DBField::create('SS_Datetime', $start->Value);
			$sql_start = $start->Rfc2822();
			$start->setValue($start->format('U') + $increment);
			$sql_end = $start->Rfc2822();
			
			$hits = $this->getUniqueHits("`LinkHit`.`Created` BETWEEN '$sql_start' AND '$sql_end'");
			$clicks = $this->getClicks("`LinkHit`.`Created` BETWEEN '$sql_start' AND '$sql_end'");
			
			$set->push(new ArrayData(array(
				'Datetime' => $date,
				'Hits' => $hits,
				'Clicks' => ($clicks) ? $clicks->count() : 0
			)));
		}
		
		return $set;
	}
	
	/**
	 * Gets a list of sources that recorded a hit
	 * 
	 * @return DataObject of ArrayData(
	 * 		'Source' => DataObject|string, the source of the hit
	 * 		'Viewed' => SS_Datetime, the first time the source viewed it
	 * 		'Clicks' => Int the number of times the source clicked a link
	 * )
	 */ 
	public function getHitsBySources() {
		$set = new DataObjectSet();
		//Complex, nested SQL is the best way to get the first hit when GROUP BY is used
		$sql = "SELECT `IP`, `Created`, `LinkID`
		FROM (
			SELECT `LinkHit`.`ID`, `LinkHit`.`IP`, `LinkHit`.`Created`, `LinkHit`.`LinkID`, `TrackedLink`.`SourceID`, `TrackedLink`.`SourceType`
			FROM LinkHit 
			INNER JOIN TrackedLink ON `LinkHit`.`LinkID` = `TrackedLink`.`ID` 
			WHERE (`TrackedLink`.`ObserverType` = '{$this->owner->ClassName}') AND (`TrackedLink`.`ObserverID` = {$this->owner->ID}) 
			ORDER BY `LinkHit`.`Created`
		) AS s
		GROUP BY `SourceID`, `SourceType`";
		
		$results = DB::query($sql);
		
		foreach ($results as $result) {
			$link = DataObject::get_by_id('TrackedLink', $result['LinkID']);
			$viewed = DBField::create('SS_Datetime', $result['Created']);
			$source = $link->getSource();
			$clicks = $this->getClicks("`TrackedLink`.`SourceType` = '{$source->ClassName}' AND `TrackedLink`.`SourceID` = '{$source->ID}'" );
			$set->push(new ArrayData(array(
				'Source' => $source,
				'Viewed' => $viewed,
				'Clicks' => ($clicks) ? $clicks->count() : 0
			)));
		}
		
		return $set;
	}
	
	/**
	 * Gets a list of urls that recorded a hit
	 * 
	 * @return DataObject of ArrayData(
	 * 		'Destination' => string, the destination of the hit
	 * 		'Clicks' => Int the number of times the source clicked a link
	 * )
	 */	
	public function getClicksByURL($limit = null) {
		$set = new DataObjectSet();
		$query = new SQLQuery();
		$query->select(array('`Destination`', 'count(*) As `Count`'));
		$query->from('`TrackedLink`');
		$query->innerJoin('LinkHit', '`LinkHit`.`LinkID` = `TrackedLink`.`ID`');
		$query->where(array(
			"`TrackedLink`.`ObserverType` = '{$this->owner->ClassName}'",
			"`TrackedLink`.`ObserverID` = {$this->owner->ID}",
			"`TrackedLink`.`LinkType` = 'Redirect'"
		));
		$query->groupby('`Destination`');
		$query->orderby(array('sort' => 'Count', 'dir' =>'DESC'));
		if ($limit) {
			$query->limit($limit);
		}
		
		$results = $query->execute();
		foreach ($results as $result) {
			$set->push(new ArrayData(array(
				'Destination' => $result['Destination'],
				'Count' => $result['Count']
			)));
		}
		
		return $set;
	}
}
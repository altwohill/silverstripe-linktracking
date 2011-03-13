<?php
/**
 * Handles the redirection or transparent proxying of tracked URLs.
 * Also records the hit.
 *
 * @package linktracking
 * @author Al Twohill <firstname@hol.net.nz>
 **/
class TrackedLinkRedirector extends Controller {
	
	public static $url_handlers = array(
		'' => 'index',
		'$Slug' => 'handleSlug',
	);
	
	public function index($request) {
		return ErrorPage::response_for(404);
	}
	
	/**
	 * Find the link by the slug and deal with it
	 */
	public function handleSlug($request) {
		if (isset($this->urlParams['Slug'])) {
			$sql_slug = Convert::raw2sql($this->urlParams['Slug']);
			$link = DataObject::get_one('TrackedLink', "Slug = '$sql_slug'");
			if ($link) {
				//Record the hit
				$hit = new LinkHit();
				$hit->Datetime = time();
				$hit->IP = $_SERVER['REMOTE_ADDR'];
				$hit->LinkID = $link->ID;
				$hit->write();
				
				//Do the action
				switch ($link->LinkType) {
					case 'Redirect' :
						return $this->redirect($link->Destination);

					case 'DirectDownload' :
						//Use CURL to grab the url and pass it on directly.
						//This allows images to be located at this url
						$session = curl_init($link->Destination);
						curl_setopt($session, CURLOPT_HEADER, false);
						curl_setopt($session, CURLOPT_FOLLOWLOCATION, true); 
						curl_setopt($session, CURLOPT_RETURNTRANSFER, true);
						$data = curl_exec($session);
						$mime = curl_getinfo($session, CURLINFO_CONTENT_TYPE);
						
						$response = new SS_HTTPResponse();
						$response->addHeader('Content-Type', $mime);
						$response->setBody($data);
						curl_close($session);
						return $response;
				}
			}
		}
		//If we had an error, or can't find the link from the slug, just 404
		return ErrorPage::response_for(404);
	}
}
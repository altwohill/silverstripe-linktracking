<?php
/**
 * Test suite for linktracking module. Run tests by going to
 * http://mysite.com/dev/tests/module/linktracking
 *
 * @package linktracking
 * @author Al Twohill <firstname@hol.net.nz>
 **/
class TrackedLinkTest extends FunctionalTest {
	static $fixture_file = 'linktracking/tests/TrackedLink.yml';
	
	/**
	 * Test that redirecting tracking links do redirect
	 */
	public function testRedirect() {
		$this->autoFollowRedirection = false;
		$response = $this->get('go/abc123');
		$this->assertEquals(302, $response->getStatusCode());
		$headers = $response->getHeaders();
		$this->assertEquals('http://www.google.co.nz',  $headers['Location']);
	}
	
	/**
	 * Test that direct download links transparently download the data
	 */
	public function testDirectDownload() {
		$response = $this->get('go/123abc');
		$this->assertEquals(200, $response->getStatusCode());
		$this->assertRegExp('/Google/', $response->getBody());
	}
	
	/**
	 * Test that access to tracked links is recorded
	 */
	public function testLinkTracking() {
		$link = $this->objFromFixture('TrackedLink', 'redirect');
		
		//Get all the hits in the yml file alread
		$hits = $link->Hits()->count();
		//Hit it again
		$this->get('go/' . $link->Slug);
		//We should now have one more hit
		$this->assertEquals(($hits + 1), $link->Hits()->count());
	}
	
	/**
	 * Test that the source is saved and retrieved correctly
	 * regardless of whether it is a string or a dataobject
	 */
	public function testSourceActions() {
		$member = $this->objFromFixture('Member', 'test');
		
		$link = TrackedLink::create('http://google.co.nz', $member);
		$this->assertEquals('Member', $link->SourceType);
		$this->assertEquals($member->ID, $link->SourceID);
		$this->assertEquals($member, $link->getSource());
		
		$link = TrackedLink::create('http://google.co.nz', 'test-source');
		$this->assertEquals('test-source', $link->SourceType);
		$this->assertEquals(null, $link->SourceID);
		$this->assertEquals('test-source', $link->getSource()->Name);
	}
	
	/**
	 * Test that the parser correctly scans for urls and creates tracked
	 * versions of them.
	 */
	public function testCreateFromContent() {
		$content = 
			"<p>Some text <a href=\"http://google.co.nz\" target='_blank'>link to Google</a>. 
			Followed by <a href='http://example.com'>Another Link</a><img src='/some/image.png' alt='Some image'/>.
			<a href='mailto:test@example.com'>Email Me!</a></p>";
		
		$newContent = TrackedLink::create_from_content($content, 'test-source', Director::absoluteBaseURL() . 'go/');
		//Debug::show($content);
		//Debug::show($newContent);
		
		
		$this->assertRegExp("/<a href=['\"]\S*?go\/\S*?['\"] target=\"_blank\"\>link to Google/", $newContent);
		$this->assertRegExp("/<a href=['\"]\S*?go\/\S*?['\"].*?\>Another Link/", $newContent);
		$this->assertRegExp("/<img src=['\"]\S*?go\/\S*?['\"] alt=\"Some image\"\/\>/", $newContent);
		$this->assertRegExp("/<a href=\"mailto:test@example.com\"\>Email Me!/", $newContent);
		
		//A link to /some/image.png should have been created
		$this->assertEquals(1, DataObject::get('TrackedLink', "`Destination` = '/some/image.png'")->count());
	}
	
	/**
	 * Tests that the Observer correctly calculates the number of hits
	 */
	public function testObserver() {
		$obj = $this->objFromFixture('Member', 'test');
		
		//Unique hits either came from a different IP address or a
		// different source.
		$this->assertEquals(3, $obj->getUniqueHits());
		
		//Get all the links that are being observed by our object
		$this->assertEquals(2, $obj->getLinks()->count());
		
		//Clicks are counted regardless of if they are unique
		$this->assertEquals(4, $obj->getClicks()->count());
	}
}

DataObject::add_extension('Member', 'Observer');
<?php
/**
 * Short urls will be of the form http://mysite/go/$Slug
 */
Director::addRules(100, array(
    'go//$Slug' => 'TrackedLinkRedirector'
));

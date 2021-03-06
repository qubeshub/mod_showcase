<?php
/**
 * @package    hubzero-cms
 * @copyright  Copyright (c) 2005-2020 The Regents of the University of California.
 * @license    http://opensource.org/licenses/MIT MIT
 */

namespace Modules\Showcase;

use Hubzero\Module\Module;
use Hubzero\User\Group;
use Components\Publications\Models\Publication;
use Components\Partners\Models\Partner;
use Components\Blog\Models\Entry;
use Components\Newsletter\Models\Newsletter;
use Components\Fmns\Models\Fmn;

include_once \Component::path('com_publications') . DS . 'models' . DS . 'publication.php';
include_once \Component::path('com_partners') . DS . 'models' . DS . 'partner.php';
include_once \Component::path('com_blog') . DS . 'models' . DS . 'entry.php';
include_once \Component::path('com_newsletter') . DS . 'models' . DS . 'newsletter.php';
include_once \Component::path('com_fmns') . DS . 'models' . DS . 'fmn.php';

/**
 * Mod_Showcase helper class, used to query for billboards and contains the display method
 */
class Helper extends Module
{
	protected $db = null;

	protected $groups = [];

	protected $pubs = [];

	protected $partners = [];
	
	protected $blogs = [];
	
	protected $newsletters = [];
	
	protected $fmns = [];

	protected $featured = array(
		"groups" => [],
		"pubs" => [],
		"partners" => [],
		"blogs" => [],
		"newsletters" => [],
		"fmns" => array(
			"open" => [],
			"current" => [],
			"upcoming" => []
		)
	);
	
	/**
	 * Get the list of billboads in the selected collection
	 *
	 * @return retrieved rows
	 */
	private function _getBillboards($collection)
	{
		// Query to grab all the billboards associated with the selected collection
		// Make sure we only grab published billboards
		$query = 'SELECT b.*, c.name' .
				' FROM #__billboards_billboards as b, #__billboards_collections as c' .
				' WHERE c.id = b.collection_id' .
				' AND published = 1' .
				' AND c.name = ' . $this->db->quote($collection) .
				' ORDER BY `ordering` ASC';

		$this->db->setQuery($query);
		$rows = $this->db->loadObjectList();

		return $rows;
	}

	/**
	 * Get the most recent publications.
	 * @return array Publications, ordered by most recent.
	 */
	private function _getPublications($item)
	{
		if (empty($this->pubs)) {
			//query to get publications
			$sql = 'SELECT V.*, C.id as id, C.category, C.project_id, C.access as master_access, C.checked_out, C.checked_out_time, C.rating as master_rating, C.group_owner, C.master_type, C.master_doi, C.ranking as master_ranking, C.times_rated as master_times_rated, C.alias, V.id as version_id, t.name AS cat_name, t.alias as cat_alias, t.url_alias as cat_url, PP.alias as project_alias, PP.title as project_title, PP.state as project_status, PP.private as project_private, PP.provisioned as project_provisioned, MT.alias as base, MT.params as type_params, (SELECT vv.version_label FROM #__publication_versions as vv WHERE vv.publication_id=C.id AND vv.state=3 ORDER BY ID DESC LIMIT 1) AS dev_version_label , (SELECT COUNT(*) FROM #__publication_versions WHERE publication_id=C.id AND state!=3) AS versions FROM #__publication_versions as V, #__projects as PP, #__publication_master_types AS MT, #__publications AS C LEFT JOIN #__publication_categories AS t ON t.id=C.category WHERE V.publication_id=C.id AND MT.id=C.master_type AND PP.id = C.project_id AND V.id = (SELECT MAX(wv2.id) FROM #__publication_versions AS wv2 WHERE wv2.publication_id = C.id AND state!=3)';

			// ' Janky fix for weird code formatting in Atom

			// Address embargo period and version publish status
			$this->db->setQuery($sql . ' AND V.state = 1 AND (DATE(V.published_up) <= CURDATE()) GROUP BY C.id ORDER BY V.published_up DESC');
			if (!$this->db->getError())
			{
				$this->pubs = $this->db->loadObjectList('id');
			}

			// Get featured publications
			$this->db->setQuery($sql . ' AND C.featured = 1 AND V.state = 1 GROUP BY C.id ORDER BY V.published_up DESC');
			if (!$this->db->getError())
			{
				$this->featured["pubs"] = $this->db->loadObjectList('id');
			}
		}

		return $this->_filter($item, 'pubs');
	}

	/**
	 * Get groups.
	 *
	 * We are mirroring code at Hubzero\User\Group\Helper::getFeaturedGroups()
	 * @return true
	 */
	private function _getGroups($item)
	{
		if (empty($this->groups)) {
			//query to get groups
			$sql = "SELECT g.gidNumber, g.cn, g.description, g.public_desc, g.created
					FROM `#__xgroups` AS g
					WHERE (g.type=1
					OR g.type=3)
					AND g.published=1
					AND g.approved=1
					AND g.discoverability=0";

			$this->db->setQuery($sql . " ORDER BY `created` DESC;");
			if (!$this->db->getError())
			{
				$this->groups = $this->db->loadObjectList('gidNumber');
			}

			// Get the featured group list (whether we need it or not)
			$featuredGroupList = \Component::params('com_groups')->get('intro_featuredgroups_list', '');
			$featuredGroupList = array_map('trim', array_filter(explode(',', $featuredGroupList), 'trim'));
			$sql_feat = $sql . "	AND g.cn IN ('" . implode("','", $featuredGroupList) . "')";

			$this->db->setQuery($sql_feat . "ORDER BY `created` DESC;");
			if (!$this->db->getError())
			{
				$this->featured["groups"] = $this->db->loadObjectList('gidNumber');
			}
		}

		return $this->_filter($item, 'groups');
	}

	/**
	 * Get partners.
	 * @return array Partners
	 */
	private function _getPartners($item)
	{
		if (empty($this->partners))
		{
			$sql = "SELECT p.*
					FROM `#__partner_partners` AS p
					WHERE p.state=1";

			$this->db->setQuery($sql . " ORDER BY `date_joined` DESC;");
			if (!$this->db->getError())
			{
				$this->partners = $this->db->loadObjectList('id');
			}

			// Get featured partners
			$this->db->setQuery($sql . ' AND p.featured=1 ORDER BY `date_joined` DESC;');
			if (!$this->db->getError())
			{
				$this->featured["partners"] = $this->db->loadObjectList('id');
			}
		}

		return $this->_filter($item, 'partners');
	}
	
	/**
	 * Get blogs.
	 * @return array Blogs
	 */
	private function _getBlogs($item)
	{
		if (empty($this->blogs))
		{
			$sql = "SELECT b.id
					FROM `#__blog_entries` AS b
					WHERE (b.state=1
					AND b.scope='site')";

			$this->db->setQuery($sql . " ORDER BY `id` DESC;");
			if ($this->blogs = $this->db->loadObjectList('id'))
			{
				foreach ($this->blogs as $id => $result)
				{
					$this->blogs[$id] = Entry::oneOrFail($id);
				}
			}
		}

		return $this->_filter($item, 'blogs');
	}

	/**
	 * Get newsletters.
	 * @return array Newsletters
	 */
	private function _getNewsletters($item)
	{
		if (empty($this->newsletters))
		{
			// Template name will be used for autotagging
			$sql = "SELECT n.id,
       							 t.name
							FROM `#__newsletters` AS n
							LEFT JOIN `#__newsletter_templates` as t 
							  ON n.template_id = t.id
							WHERE n.published = 1 AND n.deleted = 0";
			
			$this->db->setQuery($sql . " ORDER BY `id` DESC;");
			if ($this->newsletters = $this->db->loadObjectList('id'))
			{
				foreach ($this->newsletters as $id => $result)
				{
					$this->newsletters[$id] = Newsletter::oneOrFail($id);
				}
			}

			// Get specific newsletter, stored in featured argument
			if ($item["featured"]) {
				$this->db->setQuery($sql . ' AND LOWER(t.name) = LOWER(' . $this->db->quote($item["featured"]) . ') ORDER BY `id` DESC;');
				if ($this->featured["newsletters"] = $this->db->loadObjectList('id'))
				{
					foreach ($this->featured["newsletters"] as $id => $result)
					{
						$this->featured["newsletters"][$id] = Newsletter::oneOrFail($id);
					}
				}
			}
		}

		return $this->_filter($item, 'newsletters');
	}
	
	/**
	 * Get newsletters.
	 * @return array Newsletters
	 */
	private function _getFmns($item)
	{
		$sql = "SELECT *
						FROM `#__fmn_fmns`
						WHERE state = 1";
						
		// Get all fmns
		if (empty($this->fmns))
		{
			$this->db->setQuery($sql . " ORDER BY `start_date` DESC;");
			if ($this->fmns = $this->db->loadObjectList('id'))
			{
				foreach ($this->fmns as $id => $result)
				{
					$this->fmns[$id] = Fmn::oneOrFail($id);
				}
			}
		}

		// Get specific fmns, stored in featured argument
		if (($item["featured"]) && empty($this->featured["fmns"][$item["featured"]])) {
			switch ($item["featured"])
			{
				case "open":
					$sql .= " AND reg_status = 1";
				break;
				
				case "current":
					$sql .= " AND (CURDATE() >= start_date) AND (CURDATE() <= stop_date)";
				break;
				
				case "upcoming":
					$sql .= " AND CURDATE() < start_date";
				break;
				
				default:
					$sql .= " AND featured = 1";
				break;
			}
			$this->db->setQuery($sql . " ORDER BY `start_date` DESC;");
			if ($this->featured["fmns"][$item["featured"]] = $this->db->loadObjectList('id'))
			{
				foreach ($this->featured["fmns"][$item["featured"]] as $id => $result)
				{
					$this->featured["fmns"][$item["featured"]][$id] = Fmn::oneOrFail($id);
				}
			}
		}

		// Filter results based on recent, random, or indexed
		return $this->_filter($item, 'fmns', ($item["featured"] ? $item["featured"] : NULL));
	}
	
	/**
	 * Parse the item specifications.
	 * @return [type] [description]
	 */
	private function _parseItems()
	{
		$str_items = $this->params->get('items');

		$separator = "\r\n";
		$str_item = true;
		$items = array();
		$i = 0;
		while ($str_item !== false) {
			if ($i == 0) {
				$str_item = strtok($str_items, $separator);
			} else {
  			$str_item = strtok($separator);
  		}

  		if ($str_item !== false) {
  			$item = explode(',', $str_item);
  			$items[] = array(
  			  "n" => $item[0],
  			  "class" => $item[1],
  			  "type" => $item[2],
  			  "ordering" => $item[3],
  			  "content" => ($item[2] === 'static' ? $item[4] : strtolower($item[4])),
  			  "featured" => (($item[2] === 'dynamic' and count($item) > 5) ? $item[5] : 0),
  			  "indices" => (($item[3] === 'indexed' and count($item) > 5) ? explode(';', $item[5]) : 0),
  			  "tag" => 0, // Set this below
  			  "tag-target" => 0 // Set this below
  			);

  			// Add autotags to dynamic content
  			if (($this->autotag) and ($items[$i]["type"] === 'dynamic')) {
  				if ($items[$i]["content"] === 'publications') {
  					$items[$i]["tag"] = "Resource";
  				} elseif ($items[$i]["content"] === 'fmns') {
					  $items[$i]["tag"] = ""; // Setting this on view (see _fmns.php)
					} else {
  					$items[$i]["tag"] = ucfirst(rtrim($items[$i]["content"], 's'));
  				}
  				switch ($items[$i]["content"])
  				{
  					case 'publications':
  						$items[$i]["tag-target"] = Route::url('index.php?option=com_publications');
  					break;

  					case 'groups':
  						$items[$i]["tag-target"] = Route::url('index.php?option=com_groups');
  					break;

  					case 'partners':
  						$items[$i]["tag-target"] = Route::url('index.php?option=com_partners');
  					break;

						case 'blogs':
  						$items[$i]["tag-target"] = Route::url('index.php?option=com_blog');
  					break;
						
						case 'newsletters':
							$items[$i]["tag-target"] = Route::url('index.php?option=com_newsletter');
						break;
						
						case 'fmns':
							$items[$i]["tag-target"] = Route::url('index.php?option=com_fmns');
						break;

  					default:
  					break;
  				}
  			}

  			// Add optional tag
				$tag = 0;
				if (($items[$i]["indices"] or $items[$i]["featured"]) and count($item) > 6) {
					$tag = explode(';', $item[6]);
				} elseif ((!$items[$i]["indices"] and !$items[$i]["featured"]) and count($item) > 5) {
					$tag = explode(';', $item[5]);
				}
				if ($tag) {
					$items[$i]["tag"] = $tag[0];
					$items[$i]["tag-target"] = (count($tag) > 1 ? $tag[1] : 0);
				}
  		}
  		$i++;
		}

		return $items;
	}

	/**
	 * Associative array shuffle
	 * @param  array $list Unshuffled associative array
	 * @return array       Shuffled associative array
	 */
	private function shuffle_assoc($list) {
		if (!is_array($list)) return $list;

		$keys = array_keys($list);
		shuffle($keys);
		$random = array();
		foreach ($keys as $key) {
  		$random[$key] = $list[$key];
		}

		return $random;
	}
	
	/**
	 * Get the list of billboads in the selected collection
	 *
	 * @return retrieved rows
	 */
	private function _filter($item, $type, $subtype = NULL)
	{
		// Allow for subtypes
		if (is_null($subtype)) {
			$featured = &$this->featured[$type];
		} else {
			$featured = &$this->featured[$type][$subtype];
		}
		
		// Parse the number of requested items
		$n = ($item["featured"] ? count($featured) : count($this->$type));
		$n = min($n, ($item["n"] === '*' ? INF : (int) $item["n"]));
		if (($item["n"] !== '*') && ($n < (int) $item["n"])) {
			echo 'Showcase Module Error: Not enough requested ' . $type . ' left!';
			return [];
		}

		if ($item["ordering"] === "recent") {
			if ($item["featured"]) {
				$items = array_slice($featured, 0, $n, $preserve = true);
			} else {
				$items = array_slice($this->$type, 0, $n, $preserve = true);
			}
		} elseif ($item["ordering"] === "random") {
			if ($item["featured"]) {
				$rind = array_flip((array)array_rand($featured, $n));
				$items = $this->shuffle_assoc(array_intersect_key($featured, $rind));
			} else {
				$rind = array_flip((array)array_rand($this->$type, $n));
				$items = $this->shuffle_assoc(array_intersect_key($this->$type, $rind));
			}
		} elseif ($item["ordering"] === "indexed") {
			// Just use array_intersect_keys silly!
			$items = array_filter($this->$type, function($thing) use ($item, $type) {
				$key = 'id';
				if ($type == 'groups') {
					$key = 'gidNumber';
				}
				return in_array($thing->$key, $item["indices"]);
			});
		} else {
			echo 'Showcase Module Error: Unknown ordering "' . $item["ordering"] . '".  Possible values include "recent", "random", or "indexed".';
			return [];
		}
		
		// Remove used items from master lists
		$this->$type = array_diff_key($this->$type, $items);
		$featured = array_diff_key($featured, $items);
		
		return $items;
	}
	
	// https://stackoverflow.com/questions/2113940/compare-given-date-with-today
	// These three functions are used for FMN tagging
	private function _isToday($time) // midnight second
	{
    return (strtotime($time) === strtotime('today'));
	}

	private function _isPast($time)
	{
	    return (strtotime($time) < time());
	}

	private function _isFuture($time)
	{
	    return (strtotime($time) > time());
	}

	/**
	 * Display method
	 * Used to add CSS for each slide as well as the javascript file(s) and the parameterized function
	 *
	 * @return void
	 */
	public function display()
	{
		$this->css();

		$this->db = \App::get('db');

		$this->autotag = $this->params->get('autotag');
		$this->items = $this->_parseItems();

		// Get the billboard background location from the billboards parameters
		$params = \Component::params('com_billboards');
		$image_location = $params->get('image_location', '/app/site/media/images/billboards/');
		if ($image_location == '/site/media/images/billboards/')
		{
			$image_location = '/app' . $image_location;
		}
		$this->image_location = $image_location;

		require $this->getLayoutPath();
	}
}

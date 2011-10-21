<?php
/*
 Plugin Name: lexicon
 Plugin URI: http://schipplock.de
 Description: Create an easy to use lexicon.
 Author: Andreas Schipplock
 Version: 2.0
 Author URI: http://schipplock.de
 */
require_once(dirname(__FILE__).'/../../../wp-config.php');
require_once(dirname(__FILE__).'/libs/htmlparser.inc.php');

$config = parse_ini_file(dirname(__FILE__).'/config.ini');

$table_name = $config[table_name];
$seo = $config[seo];
$seo_titles = $config[seo_titles];
$show_footer = $config[show_footer];

class Lexicon {
	function Lexicon() {
		if (isset($this)) {
			add_action('init', array(&$this, 'as_lexicon_init'));
			add_action('admin_menu', array(&$this, 'as_lexicon_admin'));
			add_filter('the_content', array(&$this, 'as_lexicon_look_for_keywords'));
			add_filter('rewrite_rules_array', array(&$this, 'as_lexicon_influence_rewrite_rules'));
			add_filter('query_vars', array(&$this, 'as_lexicon_query_vars'));
			add_action('parse_query', array(&$this, 'as_lexicon_parse_query'));
		}
	}

	function sanitize_keyword($keyword) {
		return $keyword;
	}

	function as_lexicon_init() {
		global $wp_rewrite;
		$this->as_lexicon_setup();
		$wp_rewrite->flush_rules();
	}

	function as_lexicon_setup() {
		global $wpdb;
		global $table_name;

		if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
			$sql = "CREATE TABLE ".$table_name." (
						keyword varchar(255) not null,
						content text not null,
						unique key keyword (keyword)
					);";
			mysql_query($sql) or file_put_contents(dirname(__FILE__).'/error.log', mysql_error());
				
			//adding initial data
			$insert = "INSERT INTO " . $table_name .
			            " (keyword,content) " .
			            "VALUES ('question','the question is what is the question')";

			$results = mysql_query( $insert );
		}
	}


	function as_lexicon_admin() {
		add_submenu_page('post-new.php', 'Add Lexicon entry', 'Add Lexicon entry', 8, "lexicon_add", array(&$this, 'as_lexicon_write'));
		add_submenu_page('edit.php', 'Edit Lexicon entry', 'Edit Lexicon entry', 8, "lexicon_manage", array(&$this, 'as_lexicon_manage'));
	}

	function as_lexicon_manage() {
		global $wpdb;
		global $wp_query;
		global $table_name;

		# if a deletion was requested
		if ($_GET["action"] == "delete") {
			$sql = "delete from ".$table_name." where keyword = '".$_GET["id"]."';";
			$res = mysql_query($sql) or die("dberr on delete");
			$message = "Your lexicon entry has been successfully deleted.";
			require(dirname(__FILE__)."/templates/message.html");
		}

		# edit a keyword entry
		if ($_GET["action"] == "edit") {
			if ($_GET["updateData"] == "true") {
				# update the data for the keyword in database
				$status = "ok";
				if ($_POST["post_title"]=="") {
					$message = "ERROR: the keyword cannot be empty.";
					require(dirname(__FILE__)."/templates/message.html");
					$status = "failed";
				}
				if ($_POST["content"]=="") {
					$message = "ERROR: the content cannot be empty.";
					require(dirname(__FILE__)."/templates/message.html");
					$status = "failed";
				}
				if ($status != "failed") {
					$id = $_GET["id"];
					$keyword = $this->sanitize_keyword($_POST["post_title"]);
					$content = $_POST["content"];
					$wpdb->update($table_name, array(keyword=>$keyword, content=>$content), array(keyword=>$id));
					$message = "Success.";
					require_once(dirname(__FILE__)."/templates/message.html");

					$title = "Edit Lexicon Entry";
					$action = "edit.php?page=lexicon_manage&action=edit&id=$id&updateData=true";
					require_once(dirname(__FILE__)."/templates/write.html");
				}
			} else {
				# display the editor with content for the keyword
				$id = $_GET["id"];
				$sql = "select keyword,content from $table_name where keyword='$id' limit 1;";
				$res = mysql_query($sql) or die("dberr");
				list($keyword,$content)=mysql_fetch_array($res);
				$content = stripslashes($content);
				$title = "Edit Lexicon Entry";
				$action = "edit.php?page=lexicon_manage&action=edit&id=$id&updateData=true";
				require_once(dirname(__FILE__)."/templates/write.html");
			}
		}

		# list all lexicon keywords/entries
		$sql = "select keyword from ".$table_name." order by keyword desc;";
		$res = mysql_query($sql) or die("dberr");

		$tableData = array();
		while(list($keyword)=mysql_fetch_array($res)) {
			array_push($tableData, array("id"=>$keyword,"keyword"=>$keyword));
		}

		require_once(dirname(__FILE__)."/templates/manage.html");
	}

	function as_lexicon_look_for_keywords($content) {

		global $wpdb;
		global $wp_query;
		global $table_name;
		global $seo;

		# get all keywords from database
		$sql = "select keyword from $table_name;";
		$res = mysql_query($sql) or die("dberr on the_content");
		$newcontent = "";
		$keywordcount = 0;
		$keywords = array();
		#build the keywords array
		while(list($keyword)=mysql_fetch_array($res)) {
			# when keyword is found
			if (strpos($content,$keyword)!==false) {
				$keywordcount++;
				array_push($keywords, array("name"=>$keyword));
			}
		}
		# parse the html (if any) for text elements
		# only in text elements we insert the href's to our lexicon
		# prepare the html parser object
		$parser = new HtmlParser($content);
		$blogUrl = get_bloginfo('url');

		while ($parser->parse()) {
			if ($parser->iNodeType == NODE_TYPE_TEXT) {
				for ($run=0;$run<count($keywords);$run++) {
					# in case a keyword contains whitespaces
					$dKeyword = str_replace(" ", "-", $keywords[$run]["name"]);
					if ($seo==false) {
						$parser->iNodeValue = str_replace($keywords[$run]["name"],"<a href=\"" . $blogUrl . "?asenciclopedia=true&id=".$keywords[$run]["name"]."\">".$keywords[$run]["name"]."</a>", $parser->iNodeValue);
					} else {
						$parser->iNodeValue = str_replace($keywords[$run]["name"],"<a href=\"" . $blogUrl . "/lexicon/$dKeyword\">".$keywords[$run]["name"]."</a>", $parser->iNodeValue);
					}
				}
				$newcontent .= $parser->iNodeValue;
			} else {
				if ($parser->iNodeType == NODE_TYPE_ENDELEMENT) {
					$newcontent .= "</".$parser->iNodeName;
				} else {
					if (count($parser->iNodeAttributes)==0) {
						$newcontent .= "<".$parser->iNodeName;
					} else {
						$newcontent .= "<".$parser->iNodeName." ";
					}
				}
				$counter = 0;
				foreach($parser->iNodeAttributes as $index=>$value) {
					$counter++;
					if ($counter<=(count($parser->iNodeAttributes)-1)) {
						$newcontent .= $index."=\"".$value."\" ";
					} else {
						$newcontent .= $index."=\"".$value."\"";
					}
				}
				if ($parser->iNodeName=="img") {
					$newcontent .= " />";
				} else {
					$newcontent .= ">";
				}
			}
		}

		if ($keywordcount==0) {
			$newcontent = $content;
		}
		if ($wp_query->query_vars['asenciclopedia']=="true") {
			$newcontent = $content;
		}

		return $newcontent;
	}

	function as_lexicon_write() {
		global $wpdb;
		global $table_name;

		if ($_POST["action"]=="save") {
			$keyword = $this->sanitize_keyword($_POST["post_title"]);
			$content = $_POST["content"];
				
			# check if keyword already exists
			$res = mysql_query("select keyword from $table_name where keyword='$keyword' limit 1;");
			list($dbkeyword)=mysql_fetch_array($res);
			if ($dbkeyword!=$keyword) {
				$wpdb->insert($table_name, array(keyword => $keyword, content => $content));
				$message = "Your lexicon entry has been saved.";
				$keyword = "";
				$content = "";
				require_once(dirname(__FILE__)."/templates/message.html");
			} else {
				$content = stripslashes($_POST["content"]);
				$message = "";
				if ($keyword=="") {
					$message = "ERROR: the keyword cannot be empty.";
					require(dirname(__FILE__)."/templates/message.html");
				}
				if ($content=="") {
					$message = "ERROR: the content cannot be empty.";
					require(dirname(__FILE__)."/templates/message.html");
				}
				if ($keyword != "") {
					$message = "ERROR: the keyword already exists in database.";
					require(dirname(__FILE__)."/templates/message.html");
				}
			}
		}
		$title = "Write Lexicon Entry";
		$action = "edit.php?page=lexicon_add";
		require_once(dirname(__FILE__)."/templates/write.html");
	}

	function as_lexicon_influence_posts($posts) {
		global $wpdb;
		global $wp_query;
		global $table_name;
		global $seo_titles;
		global $show_footer;

		$showFooter = $show_footer;
		

		# if you set seo titles to yes the blogname and title will be changed
		# but only if you are viewing a lexicon entry of course
		if ($seo_titles=="true") {
			add_filter('wp_title', array(&$this, 'as_lexicon_modify_title'));
			add_filter('bloginfo', array(&$this, 'as_lexicon_bloginfo'), 1, 2);
		}

		$id = $this->sanitize_keyword($wp_query->query_vars['id']);
		$res = mysql_query("select keyword,content from $table_name where keyword='$id' limit 1;");
		list($dbkeyword,$dbcontent)=mysql_fetch_array($res) or die("dberr on select");
		$keyword = $dbkeyword;
		$content = stripslashes($dbcontent);

		# back button?
		if (strpos($_SERVER["HTTP_REFERER"], get_option("siteurl"))!==false) {
			$backlink = "<br /><br /><a href=\"".$_SERVER["HTTP_REFERER"]."\">&lt;&lt; Back</a>";
			$content = $content.$backlink;
		}
			
		# footer?
		if ($showFooter=="true") {
			$footer = "<br /><br /><i>Powered by <a href=\"http://schipplock.de\">Schipplock's</a> lexicon plugin</i><br /><br />";
			$content = $content.$footer;
		}

		$posts[0]->{"post_content"} = $content;
		$posts[0]->{"post_title"} = $keyword;
		$posts[0]->{"ID"} = "";
		$posts[0]->{"post_author"} = "lexicon";
		$posts[0]->{"post_date"} = "";
		$posts[0]->{"post_date_gmt"} = "";
		$posts[0]->{"post_category"} = "";
		$posts[0]->{"post_excerpt"} = "";
		$posts[0]->{"post_status"} = "publish";
		$posts[0]->{"comment_status"} = "close";
		$posts[0]->{"ping_status"} = "close";
		$posts[0]->{"post_password"} = "";
		$posts[0]->{"post_name"} = "";
		$posts[0]->{"to_ping"} = "";
		$posts[0]->{"pinged"} = "";
		$posts[0]->{"post_modified"} = "";
		$posts[0]->{"post_modified_gmt"} = "";
		$posts[0]->{"post_content_filtered"} = "";
		$posts[0]->{"post_parent"} = 0;
		$posts[0]->{"guid"} = "#";
		$posts[0]->{"menu_order"} = 0;
		$posts[0]->{"post_type"} = "post";
		$posts[0]->{"post_mime_type"} = "";
		$posts[0]->{"comment_count"} = 0;
		$entry = array($posts[0]);

		return $entry;
	}

	function as_lexicon_influence_rewrite_rules($rules) {
		$newrules = array();
		$newrules['lexicon/(.+)$']='index.php?id=$matches[1]&asenciclopedia=true';
		# we need to flush the rewrite rules on init, otherwise they have no effect
		update_option("as_lexicon_did_rewrite_regen", "false", "rewrite ruleset regeneration", "no");
		return $newrules+$rules;
	}

	function as_lexicon_query_vars($vars) {
		array_push($vars, 'asenciclopedia', 'id');
		return $vars;
	}

	function as_lexicon_parse_query($query) {
		
		if ($query->query_vars['asenciclopedia']=="true") {
			add_filter('the_posts', array(&$this, 'as_lexicon_influence_posts'));
		}else{
			if($query->query_vars['pagename'] == 'lexicon'){
				add_filter('the_posts', array(&$this, 'as_lexicon_get_list'));
			}
		}
	}
	
	/**
	 * Generates an keyword list with the entries fetched from the db
	 */
	function as_lexicon_get_list(){
		global $wpdb;
		global $wp_query;
		global $table_name;
		$c = 0;
		$letters = 'abcdefghijklmnoupqrstvwxyz';
		$output = array();
		$results = array();
		
		$keywords = mysql_query("SELECT * FROM " . $table_name);
		
		while ($row = mysql_fetch_array($keywords)) {
    		$results[] = $row['keyword'];
		}
		
		$keywords = mysql_fetch_array($keywords, MYSQL_NUM);
		
		if(count($results)){
			$output[] = '<div class="lexicon-list">';
			
			while($letters[$c]){
				$output[] = '<div class="letter letter-' . $letters[$c] . '">';
				$output[] = '<h4 class="single-letter">' . $letters[$c] . '</h4>';
				foreach($results as $keyword){
					if(preg_match('/^' . $letters[$c] . '+/i', $keyword)){
						$output[] = '<div class="result result-' . $letters[$c] . '">' . $keyword . '</div>';
					}
				}
				
				$output[] = '</div>';
				$c++;
			}
			
			$output[] = '</div>';
		}
		
		$content = implode($output);
		
		$posts[0]->{"post_content"} = $content;
		$posts[0]->{"post_title"} = $keyword;
		$posts[0]->{"ID"} = "";
		$posts[0]->{"post_author"} = "lexicon";
		$posts[0]->{"post_date"} = "";
		$posts[0]->{"post_date_gmt"} = "";
		$posts[0]->{"post_category"} = "";
		$posts[0]->{"post_excerpt"} = "";
		$posts[0]->{"post_status"} = "publish";
		$posts[0]->{"comment_status"} = "close";
		$posts[0]->{"ping_status"} = "close";
		$posts[0]->{"post_password"} = "";
		$posts[0]->{"post_name"} = "";
		$posts[0]->{"to_ping"} = "";
		$posts[0]->{"pinged"} = "";
		$posts[0]->{"post_modified"} = "";
		$posts[0]->{"post_modified_gmt"} = "";
		$posts[0]->{"post_content_filtered"} = "";
		$posts[0]->{"post_parent"} = 0;
		$posts[0]->{"guid"} = "#";
		$posts[0]->{"menu_order"} = 0;
		$posts[0]->{"post_type"} = "post";
		$posts[0]->{"post_mime_type"} = "";
		$posts[0]->{"comment_count"} = 0;
		$entry = array($posts[0]);

		return $entry;
	}

	function as_lexicon_modify_title($title) {
		# I can print the title here but as almost all themes are
		# printing bloginfo('name') first before they print wp_title
		# I decided not to print the title
		# Instead I catch bloginfo('name') and override the blog's name
		# which is shown first then
		print "";
	}

	function as_lexicon_bloginfo($result='', $show='') {
		global $wpdb;
		global $wp_query;
		global $table_name;

		switch ($show) {
			case 'name':{
				$id = $this->sanitize_keyword($wp_query->query_vars['id']);
				$res = mysql_query("select keyword from $table_name where keyword='$id' limit 1;");
				list($dbkeyword)=mysql_fetch_array($res) or die("dberr on select");
				$keyword = $dbkeyword;
				$result = $keyword." - ".get_option("blogname");
				break;
			}
			default:
		}
		return $result;
	}}

$myLexicon = new Lexicon();

?>

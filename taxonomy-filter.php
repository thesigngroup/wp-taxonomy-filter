<?php 

function cftf_build_form($args) {
	$cftf = new CF_Taxonomy_Filter($args);
	$cftf->build_form();
}

function cftf_enqueue_scripts() {
	// Figure out the URL for this file.
	$parent_dir = trailingslashit(get_template_directory());
	$child_dir = trailingslashit(get_stylesheet_directory());

	$plugin_dir = trailingslashit(basename(__DIR__));
	$file = basename(__FILE__);

	if (file_exists($parent_dir.'functions/'.$plugin_dir.$file)) {
		$url = trailingslashit(get_template_directory_uri()).'functions/'.$plugin_dir;
	}
	else if (file_exists($parent_dir.'plugins/'.$plugin_dir.$file)) {
		$url = trailingslashit(get_template_directory_uri()).'plugins/'.$plugin_dir;
	}
	else if ($child_dir !== $parent_dir && file_exists($child_dir.'functions/'.$plugin_dir.$file)) {
		$url = trailingslashit(get_stylesheet_directory_uri()).'functions/'.$plugin_dir;
	}
	else if ($child_dir !== $parent_dir && file_exists($child_dir.'plugins/'.$plugin_dir.$file)) {
		$url = trailingslashit(get_stylesheet_directory_uri()).'plugins/'.$plugin_dir;
	}
	else {
		$url = plugin_dir_url(__FILE__);
	}

	wp_enqueue_script('jquery-ui-datepicker');
	wp_enqueue_script('jquery');
	wp_enqueue_script('chosen', $url.'lib/chosen/chosen/chosen.jquery.min.js', array('jquery'), null, true);
	wp_enqueue_script('cftf', $url.'/taxonomy-filter.js', array('jquery', 'chosen', 'jquery-ui-datepicker'), '1.0', true);

	wp_enqueue_style('chosen', $url.'/lib/chosen/chosen/chosen.css', array(), null, 'all');
}
add_action('wp_enqueue_scripts', 'cftf_enqueue_scripts');

CF_Taxonomy_Filter::add_actions();

class CF_Taxonomy_Filter {

	function __construct($args) {
		// These keys are always required so we don't have to think about them later.
		$default_keys = array(
			'form_options' => array(),
			'submit_options' => array(),
		);
		$this->options = array_merge($default_keys, $args);
	}

	static function add_actions() {
		add_action('pre_get_posts', array('CF_Taxonomy_Filter', 'pre_get_posts'), 11);
	}

	public function build_form() {
		self::start_form($this->options['form_options']);

		if (!empty($this->options['taxonomies'])) {
			foreach ($this->options['taxonomies'] as $taxonomy => $args) {
				if (is_array($args)) {
					self::tax_filter($taxonomy, $args);
				}
				// Just passed in taxonomy name with no options
				else {
					self::tax_filter($args);
				}
			}
		}

		if (!empty($this->options['authors'])) {
			self::author_select($this->options['authors']);
		}

		self::submit_button($this->options['submit_options']);

		self::the_content();

		self::end_form();
	}

	/**
	 * Echo a date range filter form elemtn
	 *
	 * @param $start_args array Optional array of arguments for start range input. All options are attributes on the element.
	 * @param $end_args array Optional array of arguments for end range input. All options are attributes on the element.
	 * @return void
	 **/
	public static function date_filter($start_args = array(), $end_args = array()) {
		$start_defaults = array(
			'placeholder' => __('Start Date', 'cftf'),
		);
		$end_defaults = array(
			'placeholder' => __('End Date', 'cftf'),
		);

		$start_args = array_merge($start_defaults, $start_args);
		$start_args = self::_add_class('cftf-date', $start_args);

		$end_args = array_merge($end_defaults, $end_args);
		$end_args = self::_add_class('cftf-date', $end_args);

		echo sprintf(_x('%s to %s', 'start date range input TO end date range input', 'cftf'), 
			'<input type="text" name="cftf_date[start]"'.self::_build_attrib_string($start_args).' />', 
			'<input type="text" name="cftf_date[end]"'.self::_build_attrib_string($end_args).' />'
		);
	}

	/**
	 * Echo a taxonomy filter form element.
	 *
	 * @param $taxonomy string The taxonomy slug to generate the form for
	 * @param $args array Optional array of arguments. 
	 *		'data-placeholder' is placeholder text for the input
	 *		'prefix' is a prefix added to the term dropdown. For typeahead support, users will
	 *			have to type the prefix as well.
	 *		'multiple' Determines whether or not multiple terms can be selected
	 *		'selected' is an array of term names which are preselected on initial form generation
	 * 		all additional arguments are attributes of the select box. see allowed_attributes();
	 * @return void
	 **/
	public static function tax_filter($taxonomy, $args = array()) {
		if (!taxonomy_exists($taxonomy)) {
			return;
		}

		$tax_obj = get_taxonomy($taxonomy);

		$defaults = array(
			'prefix' => '',
			'multiple' => true,
			'selected' => array(),
			'data-placeholder' => $tax_obj->labels->name,
		);

		$args = array_merge($defaults, $args);

		// Set the initially selected arguments. Try for previous queried, if none exists, get the id of the term names passed in
		if (!empty($_GET['cftf_action'])) {
			$args['selected'] = isset($_GET['cfct_tax'][$taxonomy]) ? (array) $_GET['cfct_tax'][$taxonomy] : array();
		}
		else if (!empty($args['selected'])) {
			$selected_names = (array) $args['selected'];
			$args['selected'] = array();
			foreach ($selected_names as $term_name) {
				$term = get_term_by('name', $term_name, $taxonomy);
				if ($term) {
					$args['selected'][] = $term->term_id;
				}
			}
		}

		// Always need cftf-tax-filter as a class so chosen can target it
		$args = self::_add_class('cftf-tax-select', $args);

		$terms = get_terms($taxonomy, array('hide_empty' => false));
		
		// Build the select form element
		$output = '<select name="'.esc_attr('cfct_tax['.$taxonomy.'][]').'"'.self::_build_attrib_string($args);
		if ($args['multiple']) {
			$output .= 'multiple ';
		}
		// Empty option for single select removal for Chosen
		$output .= '>
		<option value=""></option>';

		foreach ($terms as $term) {
			// @TODO allow for multiple initially selected?
			$output .= '<option value="'.esc_attr($term->term_id).'"'.selected(in_array($term->term_id, $args['selected']), true, false).'>'.esc_html($args['prefix'].$term->name).'</option>';
		}

		$output .= '</select>';

		echo $output;
	}

	/**
	 * Echo a submit form element. 
	 *
	 * @param $args array Optional array of arguments. 
	 *		'data-placeholder' is placeholder text for the input
	 *		'user_query' is an array of WP_User_Query arguments to override which
	 *			 users are selectable (no backend enforcing of these)
	 *		'selected' is an array of user ids which are preselected on initial form generation
	 * 		all additional arguments are attributes of the select box. see allowed_attributes();
	 * @return void
	 **/
	public static function author_select($args = array()) {
		$defaults = array(
			'multiple' => false,
			'selected' => array(),
			'data-placeholder' => __('Author', 'cftf'),
			'user_query' => array(
				'orderby' => 'display_name',
			)
		);

		$args = array_merge($defaults, $args);

		// Already queried, repopulate the form with selected items
		if (!empty($_GET['cftf_action'])) {
			$args['selected'] = isset($_GET['cftf_authors']) ? $_GET['cftf_authors'] : array();
		}
		$args['selected'] = (array) $args['selected'];

		// Always need cftf-author-filter as a class so chosen can target it
		$args = self::_add_class('cftf-author-select', $args);

		$user_query = new WP_User_Query($args['user_query']);
		if (!empty($user_query->results)) {
			$users = apply_filters('cftf_users', $user_query->results);
		}

		$output = '<select name="cftf_authors[]"'.self::_build_attrib_string($args);
		if ($args['multiple']) {
			$output .= 'multiple ';
		}
		// Empty option for single select removal support
		$output .= '>
		<option value=""></option>';

		foreach ($users as $user) {
			// @TODO allow for multiple select and selected? Would need to use an OR here in query
			$output .= '<option value="'.$user->ID.'"'.selected(in_array($user->ID, $args['selected']), true, false).'>'.esc_html($user->data->display_name).'</option>';
		}

		$output .= '</select>';

		echo $output;
	}

	/**
	 * Echo a submit form element. 
	 *
	 * @param $args array Optional array of arguments. 'text' is the submit button value,
	 * all additional arguments are attributes of the input. see allowed_attributes();
	 * @return void
	 **/
	public static function submit_button($args = array()) {
		$defaults = array(
			'text' => __('Submit', 'cftf'),
			'class' => '',
			'id' => '',
		);
		$args = array_merge($defaults, $args);

		echo '<input type="submit"'.self::_build_attrib_string($args).' />';
	}

	/**
	 * Opens the form tag
	 * filter data which is utilized for pagination
	 *
	 * @param $args array Option argument array, each of which are just attributes on the form element
	 * @return void
	 **/
	public static function start_form($args = array()) {
		$defaults = array(
			'id' => 'cftf-filter',
			'class' => '',
			'action' => home_url('?s='),
		);

		$args = array_merge($defaults, $args);
		$args = self::_add_class('cftf-filter', $args);

		echo '
<form method="GET"'.self::_build_attrib_string($args).'>';
	}

	// Closes the form and adds the action
	public static function end_form() {
		echo '
	<input type="hidden" name="cftf_action" value="filter" />
</form>';
	}

	/**
	 * Adds a class to a set of arguemnts. Adds the class to the end
	 * of existing classes if they exist, otherwise just sets the argument
	 * 
	 * @param String $class Class to append (on the class index)
	 * @param $args array of arguments
	 * @return Array argument array passed in with the additional class
	 **/ 
	static function _add_class($class, $args) {
		if (!empty($args['class'])) {
			$args['class'] .= ' '.$class;
		}
		else {
			$args['class'] = $class;
		}

		return $args;
	}

	/**
     * Build an attribute string for an HTML element, only attributes from
     * allowed_attributes will be allowed
     **/
	static function _build_attrib_string($attributes) {
		if (!is_array($attributes)) {
			return '';
		}
		
		$components = array();

		$allowed_attributes = self::allowed_attributes();

		foreach ($attributes as $attribute => $value) {
			if (!empty($value) && in_array($attribute, $allowed_attributes)) {
				$components[] = esc_attr($attribute).'="'.esc_attr($value).'"';	
			}
		}

		$string = implode(' ', $components);
		if (!empty($string)) {
			$string = ' '.$string.' ';
		}

		return $string;
	}

	/**
     * What attributes can be placed on the various form elements, filterable
     **/
	static function allowed_attributes() {
		return apply_filters('cftf_allowed_attributes', array(
			'class',
			'id', 
			'method',
			'action',
			'value',
			'name',
			'style',
			'placeholder',
			'data-placeholder',
			'tabindex',
		));
	}

	/**
     * Filter the WHERE clause in the query as WP_Query does not support a range function as of 3.5
     **/
	public static function posts_where($where) {
		remove_filter('posts_where', array('CF_Taxonomy_Filter', 'posts_where'));
		global $wpdb;
		
		if (!empty($_GET['cftf_date']['start'])) {
			$php_date = strtotime($_GET['cftf_date']['start']);
			$mysql_date = date('Y-m-d H:i:s', $php_date);
			$date_where = $wpdb->prepare("AND $wpdb->posts.post_date > %s", $mysql_date);
			if (!empty($where)) {
				$where .= ' '.$date_where;
			}
			else {
				$where = $date_where;
			}
		}

		if (!empty($_GET['cftf_date']['end'])) {
			$php_date = strtotime($_GET['cftf_date']['end']);
			$mysql_date = date('Y-m-d H:i:s', $php_date);
			$date_where = $wpdb->prepare("AND $wpdb->posts.post_date < %s", $mysql_date);
			if (!empty($where)) {
				$where .= ' '.$date_where;
			}
			else {
				$where = $date_where;
			}
		}

		return $where;
	}

	/**
     * Override default query with the filtered values
     **/
	public static function pre_get_posts($query_obj) {
		global $cftl_previous, $wp_rewrite;
		if (!$query_obj->is_main_query() || !isset($_GET['cftf_action']) || $_GET['cftf_action'] != 'filter') {
			return;
		}
		remove_action('pre_get_posts', array('CF_Taxonomy_Filter', 'pre_get_posts'));
		$query_args = array(
			// @TODO figure out best way to support pagination
			'posts_per_page' => -1,
		);

		// Make WordPress think this is a search and render the search page
		$query_obj->is_search = true;
		
		if (!empty($_GET['cftf_authors'])) {
			// WP_Query doesnt accept an array of authors, sad panda 8:(
			$query_obj->query_vars['author'] = implode(',', (array) $_GET['cftf_authors']);
		}

		if (!empty($_GET['cfct_tax']) && is_array($_GET['cfct_tax'])) {
			foreach ($_GET['cfct_tax'] as $taxonomy => $terms) {
				$query_obj->query_vars['tax_query'][] = array(
					'taxonomy' => $taxonomy,
					'field' => 'ids',
					'terms' => $terms,
					'include_children' => false,
					'operator' => 'AND',
				);
			}

			$query_obj->query_vars['tax_query']['relation'] = 'AND';
		}

		// Have to manually filter date range
		if (!empty($_GET['cftf_date']['start']) || !empty($_GET['cftf_date']['end'])) {
			$query_obj->query_vars['suppress_filters'] = 0;
			add_filter('posts_where', array('CF_Taxonomy_Filter', 'posts_where'));
		}
	}
}

/* Potential arguments for constructor
$args = array(
	'form_options' => array(
		// Array of allowed element attributes
	),
	'taxonomies' => array(
		'projects' => array(
			'multiple' => false,
			// Term names
			'selected' => array(
				'Project 1',
				'Project 2',
				'SecretProject'
			), 
			'prefix' => '@',
			'data-placeholder' => 'Projects'
		),
		'post_tag' => array(
			'multiple' => true,
			'selected' => array(
					'tag1',
					'you\'re it',
					'freeze tag'
				),
				'prefix' => '#',
			'data-placeholder' => 'The Great Tag Filter'
		),
	),
	'authors' => 1, // Determines wether or not to display an author filter
	'author_options' => array(
		'multiple' => true,
		'user_query' => array(
			'role' => 'editor',
		),
		// Element attributes
	),
	'submit_options' => array(
		'text' => 'Submit', // Submit button value
		// Element attributes
	),
	'date' => 1, // Determines wether or not to display a date range filter
	'date_options' => array(
		'start' => array(
			// Element attributes
		),
		'end' => array(
			// Element attributes
		)
	),
)
*/


?>
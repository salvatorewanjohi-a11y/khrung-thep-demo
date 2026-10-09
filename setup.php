<?php
/**
 * Builds the Khrung Thep store inside WordPress Playground.
 * Run by the blueprint after WooCommerce and the theme are installed.
 * Menu photos are read from /wordpress/kt-images.
 *
 * Menu, prices, options and descriptions are Khrung Thep's own, from their Uber Eats store
 * (8 Oct 2026). Blueberry Matcha, Pineapple Matcha, Nam Khao and the Corporate Lunch come from their
 * Instagram; prices marked PLACEHOLDER are ours, to confirm with the restaurant.
 */
require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// The photos are already web-sized, so each one is copied straight into uploads and registered with its
// size. (media_handle_sideload opens every image, which made the demo slow to build.)
function kt_sideload( $tmp, $name, $parent, $title ) {
	$up   = wp_upload_dir();
	$file = wp_unique_filename( $up['path'], $name );
	$dest = trailingslashit( $up['path'] ) . $file;
	if ( ! @rename( $tmp, $dest ) && ! copy( $tmp, $dest ) ) {
		return new WP_Error( 'kt_copy', 'Could not copy ' . $name );
	}
	$type = wp_check_filetype( $file );
	$size = @getimagesize( $dest ) ?: [ 0, 0 ];
	$id   = wp_insert_attachment( [ 'post_mime_type' => $type['type'], 'post_title' => $title, 'post_status' => 'inherit', 'guid' => trailingslashit( $up['url'] ) . $file ], $dest, $parent, true, false );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	wp_update_attachment_metadata( $id, [ 'width' => $size[0], 'height' => $size[1], 'file' => _wp_relative_upload_path( $dest ), 'sizes' => [], 'image_meta' => [] ] );
	return $id;
}

// One transaction for the whole import: SQLite otherwise commits (and syncs to disk) after every query.
wp_defer_term_counting( true );
wp_suspend_cache_invalidation( true );
$wpdb->query( 'START TRANSACTION' );

if ( defined( 'KT_FAST' ) && KT_FAST ) {
	add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
	add_filter( 'big_image_size_threshold', '__return_false' );
	add_filter( 'woocommerce_background_image_regeneration', '__return_false' );
	add_filter( 'woocommerce_resize_images', '__return_false' );
}

// Store settings. Delivery goes to one address (the billing one); pickup is WooCommerce's local pickup.
foreach ( [
	'blogname'                               => 'Khrung Thep',
	'blogdescription'                        => 'Thai Restaurant & Matcha Cafe · Authentic Thai street food & artisanal matcha · 208 Sports Rd, Westlands, Nairobi',
	'timezone_string'                        => 'Africa/Nairobi',
	'woocommerce_currency'                   => 'KES',
	'woocommerce_currency_pos'               => 'left_space',
	'woocommerce_price_num_decimals'         => '0',
	'woocommerce_price_thousand_sep'         => ',',
	'woocommerce_default_country'            => 'KE:KE30',
	'woocommerce_store_address'              => '208 Sports Road',
	'woocommerce_store_city'                 => 'Westlands, Nairobi',
	'woocommerce_manage_stock'               => 'no',
	'woocommerce_allowed_countries'          => 'specific',
	'woocommerce_specific_allowed_countries' => [ 'KE' ],
	'woocommerce_ship_to_countries'          => 'specific',
	'woocommerce_specific_ship_to_countries' => [ 'KE' ],
	'woocommerce_ship_to_destination'        => 'billing_only',
	'woocommerce_shipping_cost_requires_address' => 'no',
	'woocommerce_enable_shipping_calc'       => 'no',
	'woocommerce_enable_reviews'             => 'yes',
	'woocommerce_review_rating_verification_label' => 'yes',
	'woocommerce_onboarding_profile'         => [ 'skipped' => true ],
	'woocommerce_task_list_hidden'           => 'yes',
	'woocommerce_coming_soon'                => 'no',
	'woocommerce_checkout_phone_field'       => 'required',
	'woocommerce_checkout_company_field'     => 'hidden',
	'woocommerce_checkout_address_2_field'   => 'optional',
	'woocommerce_enable_coupons'             => 'yes',
	'woocommerce_calc_discounts_sequentially' => 'no',
] as $k => $v ) {
	update_option( $k, $v );
}

// Remove sample content.
foreach ( get_posts( [ 'post_type' => [ 'post', 'page' ], 'name' => 'hello-world', 'numberposts' => 1 ] ) as $p ) wp_delete_post( $p->ID, true );
$sample = get_page_by_path( 'sample-page' );
if ( $sample ) wp_delete_post( $sample->ID, true );

// Menu sections (order = order on the menu and in the filters).
$cat = [];
$i   = 0;
foreach ( [
	'starters' => [ 'Starters, Wings & Salads', 'Thai BBQ wings, crispy spring rolls, som tam papaya salad, Northern Thai laab and Nam Khao crispy rice salad.' ],
	'wok'      => [ 'Pad Thai & Wok Classics', 'Wok-fired to order: Pad Thai on our own fresh rice noodles, Pad Kra Pao, Thai fried rice, garlic prawns and lemongrass specials.' ],
	'curries'  => [ 'Thai Curries', 'Coconut-milk curries, served with jasmine rice.' ],
	'matcha'   => [ 'Ceremonial Matcha', '100% organic ceremonial-grade matcha from Japan, whisked fresh for every cup.' ],
	'drinks'   => [ 'Thai Teas, Coffee & Coolers', 'Cha yen Thai tea, Thai and Vietnamese iced coffee, ube and coconut clouds, lemongrass teas and fresh coconut water.' ],
	'desserts' => [ 'Desserts', 'Mango sticky rice, the sweet end to a Thai feast.' ],
	'deals'    => [ 'Lunch Deals', 'Weekday lunch offers for offices and quick breaks.' ],
] as $slug => [ $name, $desc ] ) {
	$t = term_exists( $slug, 'product_cat' ) ?: wp_insert_term( $name, 'product_cat', [ 'slug' => $slug, 'description' => $desc ] );
	$cat[ $slug ] = (int) $t['term_id'];
	update_term_meta( $cat[ $slug ], 'order', $i++ );
}

// Diet & ingredient tags (the menu filters). "veg" = vegetarian, or can be made vegetarian.
$tags = [];
foreach ( [
	'vegetarian'  => 'Vegetarian',
	'vegan'       => 'Vegan',
	'gluten-free' => 'Gluten-free',
	'seafood'     => 'Seafood',
	'chicken'     => 'Chicken',
	'beef'        => 'Beef',
	'peanuts'     => 'Contains peanuts',
] as $slug => $name ) {
	$t = term_exists( $slug, 'product_tag' ) ?: wp_insert_term( $name, 'product_tag', [ 'slug' => $slug ] );
	$tags[ $slug ] = (int) $t['term_id'];
}

// Payment: nothing is charged online. M-Pesa till / paybill isn't public yet (PLACEHOLDER).
update_option( 'woocommerce_cod_settings', [
	'enabled'            => 'yes',
	'title'              => 'Pay on delivery or at pickup (M-Pesa or cash)',
	'description'        => 'Pay the rider, or pay when you collect at the kerb, by M-Pesa or cash.',
	'instructions'       => 'We will call or WhatsApp to confirm your order. Pay by M-Pesa or cash when it reaches you.',
	'enable_for_methods' => [],
	'enable_for_virtual' => 'yes',
] );
update_option( 'woocommerce_bacs_settings', [
	'enabled'         => 'yes',
	'title'           => 'Pay now by M-Pesa',
	'description'     => 'We send our M-Pesa till number with your order confirmation. Pay before the rider leaves and skip the wait at the door.',
	'instructions'    => 'We will send our M-Pesa till number on WhatsApp with your confirmation.',
	'account_details' => '',
] );
update_option( 'woocommerce_bacs_accounts', [] );
update_option( 'woocommerce_cheque_settings', [ 'enabled' => 'no' ] );
update_option( 'woocommerce_gateway_order', [ 'cod' => 0, 'bacs' => 1 ] );

// Delivery: their own riders by area (fees are PLACEHOLDERS), and kerbside pickup at the compound gate.
$zone = new WC_Shipping_Zone();
$zone->set_zone_name( 'Nairobi' );
$zone->set_zone_order( 1 );
$zone->add_location( 'KE', 'country' );
$zone->save();
foreach ( [
	[ 'Rider delivery – Westlands, Parklands & Spring Valley', 200 ],
	[ 'Rider delivery – Kileleshwa, Lavington, Kilimani, Riverside & Loresho', 350 ],
	[ 'Rider delivery – Gigiri, Runda, Upper Hill, CBD & Kilimani beyond Yaya', 500 ],
] as [ $title, $cost ] ) {
	$iid = $zone->add_shipping_method( 'flat_rate' );
	update_option( 'woocommerce_flat_rate_' . $iid . '_settings', [ 'title' => $title, 'tax_status' => 'none', 'cost' => (string) $cost ] );
}
update_option( 'woocommerce_pickup_location_settings', [ 'enabled' => 'yes', 'title' => 'Kerbside pickup', 'tax_status' => 'none', 'cost' => '' ] );
update_option( 'pickup_location_pickup_locations', [ [
	'name'    => 'Khrung Thep, 208 Sports Rd',
	'address' => [ 'address_1' => '208 Sports Road', 'city' => 'Westlands, Nairobi', 'state' => '', 'postcode' => '', 'country' => 'KE' ],
	'details' => 'Call or WhatsApp 0742 093080 when you reach the gate and we bring your order to the car.',
	'enabled' => true,
] ] );

// Menu items. 'opts': spice = 1–5 chilli scale (free), extras = Uber's "Extra White Rice +200 / Extra Sauce +50",
// sweet = sweetness, milk = milk swap. 'protein' / 'sizes' become variations (the price changes).
// heat: the dish as it comes (0 none, 1 mild, 2 medium, 3 Thai hot). likes: Uber Eats thumbs-up score.
$protein = [ 'Chicken' => 0, 'Tofu' => 0, 'Prawns' => 300, 'Vegetarian' => 0, 'Vegan' => 0 ];
$products = [
	// Starters, wings & salads
	[
		'slug' => 'thai-bbq-chicken-wings', 'name' => 'Thai BBQ Chicken Wings', 'thai' => 'ปีกไก่บาร์บีคิว', 'roman' => 'Peek Gai BBQ', 'cats' => [ 'starters' ],
		'sizes' => [ 'Regular' => 900, 'Large' => 1800 ], 'opts' => [ 'spice', 'extras' ], 'heat' => 1, 'tags' => [ 'chicken' ], 'likes' => '83% liked (12)', 'badge' => 'Uber Eats favourite',
		'short' => 'Savoury chicken wings with a Thai twist: smoky, sticky Thai BBQ glaze and a perfect blend of spices.',
		'desc' => 'Our Thai-style wings, glazed in a smoky Thai BBQ sauce and finished with herbs and lime. Order Regular for one, Large to share. Add a portion of white rice or extra sauce for dipping.',
		'portion' => 'Regular or Large (to share)', 'ingredients' => [ 'Chicken wings', 'Thai BBQ glaze', 'Garlic', 'Lime', 'Fresh herbs' ], 'allergens' => [ 'Soy', 'May contain sesame' ],
	],
	[
		'slug' => 'crispy-spring-rolls', 'name' => 'Crispy Spring Rolls', 'thai' => 'ปอเปี๊ยะทอด', 'roman' => 'Por Pia Tod', 'cats' => [ 'starters' ],
		'price' => 800, 'heat' => 0, 'tags' => [ 'vegetarian' ], 'likes' => '75% liked (24)',
		'short' => 'Vegetarian spring rolls, fried golden and crisp, with sweet chilli dip.',
		'desc' => 'Thin wrappers rolled around glass noodles and vegetables, fried until crackling and served with sweet chilli sauce. The easy starter everyone at the table reaches for.',
		'portion' => 'One plate, with dip', 'ingredients' => [ 'Spring roll wrappers', 'Glass noodles', 'Cabbage & carrot', 'Sweet chilli dip' ], 'allergens' => [ 'Wheat (gluten)', 'Soy' ],
	],
	[
		'slug' => 'chicken-spring-rolls', 'name' => 'Chicken Spring Rolls', 'thai' => 'ปอเปี๊ยะไก่', 'roman' => 'Por Pia Gai', 'cats' => [ 'starters' ],
		'price' => 800, 'heat' => 0, 'tags' => [ 'chicken' ], 'likes' => '66% liked (3)',
		'short' => 'Golden spring rolls filled with seasoned chicken and vegetables, with sweet chilli dip.',
		'desc' => 'Our crispy spring rolls with a seasoned chicken filling, served with sweet chilli sauce.',
		'portion' => 'One plate, with dip', 'ingredients' => [ 'Spring roll wrappers', 'Chicken', 'Vegetables', 'Sweet chilli dip' ], 'allergens' => [ 'Wheat (gluten)', 'Soy' ],
	],
	[
		'slug' => 'papaya-salad', 'name' => 'Papaya Salad', 'thai' => 'ส้มตำ', 'roman' => 'Som Tam', 'cats' => [ 'starters' ],
		'price' => 1000, 'opts' => [ 'spice' ], 'heat' => 3, 'tags' => [ 'peanuts' ], 'likes' => '95% liked (20)', 'badge' => '95% liked on Uber Eats',
		'short' => 'Fresh and spicy green papaya salad, pounded to order: sweet, sour, salty and hot.',
		'desc' => 'Thailand\'s favourite salad. Shredded green papaya pounded in the mortar with chilli, garlic, lime, tomato, long beans and roasted peanuts. Tell us how hot: 1 is gentle, 5 is how they eat it in Isaan.',
		'portion' => 'One plate', 'ingredients' => [ 'Green papaya', 'Chilli', 'Garlic', 'Lime', 'Tomato', 'Long beans', 'Roasted peanuts' ], 'allergens' => [ 'Peanuts', 'Fish sauce (fish)' ],
	],
	[
		'slug' => 'chicken-laab', 'name' => 'Chicken Laab', 'thai' => 'ลาบไก่', 'roman' => 'Laab Gai', 'cats' => [ 'starters' ],
		'price' => 1300, 'opts' => [ 'spice', 'extras' ], 'heat' => 2, 'tags' => [ 'chicken', 'gluten-free' ], 'likes' => '76% liked (17)',
		'short' => 'Minced chicken with onions, cilantro, toasted rice powder and chilli: a Northern Thai specialty.',
		'desc' => 'A warm salad from Northern Thailand and Laos. Minced chicken tossed with shallots, cilantro, mint, lime, chilli and toasted rice powder for a nutty crunch. Add white rice to make it a meal.',
		'portion' => 'One plate', 'ingredients' => [ 'Minced chicken', 'Shallots', 'Cilantro & mint', 'Lime', 'Chilli', 'Toasted rice powder' ], 'allergens' => [ 'Fish sauce (fish)' ],
	],
	[
		'slug' => 'nam-khao', 'name' => 'Nam Khao – Crispy Rice Salad', 'thai' => 'แหนมข้าวทอด', 'roman' => 'Nam Khao', 'cats' => [ 'starters' ],
		'price' => 1200, 'placeholder_price' => true, 'opts' => [ 'spice' ], 'heat' => 2, 'tags' => [ 'vegan', 'vegetarian', 'gluten-free', 'peanuts' ], 'badge' => 'First Laotian dish in Kenya',
		'short' => 'Crispy rice salad from Laos: crunchy rice, herbs, fresh vegetables and edible flowers. 100% vegan, naturally gluten-free.',
		'desc' => 'Nam Khao comes from Laos: crisp-fried rice broken up and tossed with herbs, fresh vegetables, lime and chilli, finished with edible flowers. Crunchy, tangy, savoury and spicy, and unlike anything else in Nairobi. We\'re proud to have brought the first Laotian dish to Kenya.',
		'portion' => 'One plate', 'ingredients' => [ 'Crispy rice', 'Mint & cilantro', 'Red onion', 'Lime', 'Chilli', 'Edible flowers' ], 'allergens' => [ 'May contain peanuts' ],
	],
	// Pad Thai & wok classics
	[
		'slug' => 'pad-thai', 'name' => 'Pad Thai', 'thai' => 'ผัดไทย', 'roman' => 'Pad Thai', 'cats' => [ 'wok' ],
		'protein' => $protein, 'base_price' => 1680, 'opts' => [ 'spice' ], 'heat' => 1, 'tags' => [ 'chicken', 'seafood', 'vegetarian', 'vegan', 'gluten-free', 'peanuts' ], 'likes' => '#1 most liked', 'badge' => '#1 most liked on Uber Eats', 'clip' => 'noodles',
		'reel' => 'https://www.instagram.com/reel/DOIzob7gG2S/',
		'short' => 'Traditional Thai noodles with egg, bean sprouts, green onions and ground peanuts, on rice noodles we make fresh every day. Chicken or tofu, or upgrade to prawns.',
		'desc' => 'Importing noodles wasn\'t easy, so we make our own: fresh rice noodles, gluten-free and preservative-free, made in our kitchen every day. They\'re wok-tossed with egg, bean sprouts, spring onions and our signature tamarind sauce, then finished with crushed peanuts and fresh lime. Sweet, tangy and savoury: Thailand\'s most famous noodle dish, and our most-ordered plate.',
		'portion' => 'One plate', 'ingredients' => [ 'House-made rice noodles', 'Egg', 'Bean sprouts', 'Spring onion', 'Tamarind sauce', 'Ground peanuts', 'Lime' ], 'allergens' => [ 'Peanuts', 'Egg', 'Fish sauce (fish)', 'Shellfish (prawn option)' ],
		'vegan_note' => 'Choose Vegan and we leave out the egg and fish sauce.',
	],
	[
		'slug' => 'pad-kra-pao-gai', 'name' => 'Pad Kra Pao Gai', 'thai' => 'ผัดกะเพราไก่', 'roman' => 'Pad Kra Pao Gai', 'cats' => [ 'wok' ],
		'price' => 1580, 'opts' => [ 'spice' ], 'heat' => 3, 'tags' => [ 'chicken' ], 'likes' => '86% liked (50)', 'badge' => '#2 most liked on Uber Eats', 'clip' => 'wok',
		'reel' => 'https://www.instagram.com/reel/DJzBgxAs-8Y/',
		'short' => 'Garlic chicken stir-fry with holy basil and chilli, crowned with a crispy fried egg and jasmine rice. Bangkok street food at its best.',
		'desc' => 'Tender minced chicken stir-fried hard and fast with holy basil, garlic and chilli, served over jasmine rice and topped with a crispy-edged fried egg. Spicy, savoury and straight-up addictive: the dish every Bangkok street stall is judged by.',
		'portion' => 'One plate, with jasmine rice & fried egg', 'ingredients' => [ 'Minced chicken', 'Holy basil', 'Garlic', 'Bird\'s eye chilli', 'Fried egg', 'Jasmine rice' ], 'allergens' => [ 'Egg', 'Soy', 'Oyster sauce (shellfish)' ],
	],
	[
		'slug' => 'thai-fried-rice', 'name' => 'Thai Fried Rice', 'thai' => 'ข้าวผัด', 'roman' => 'Khao Pad', 'cats' => [ 'wok' ],
		'protein' => $protein, 'opts' => [ 'spice' ], 'heat' => 1, 'tags' => [ 'chicken', 'seafood', 'vegetarian', 'vegan' ], 'likes' => '81% liked (11)', 'clip' => 'wok',
		'reel' => 'https://www.instagram.com/reel/DKfeQamv-xK/',
		'base_price' => 1690,
		'short' => 'Savoury wok-fried jasmine rice with egg, onion and Thai basil, infused with authentic Thai flavours. Choose your protein.',
		'desc' => 'Jasmine rice wok-fried over high heat with egg, onion, tomato and Thai basil, seasoned the Thai way and served with cucumber and lime. Fragrant, savoury comfort in a bowl.',
		'portion' => 'One plate', 'ingredients' => [ 'Jasmine rice', 'Egg', 'Onion', 'Tomato', 'Thai basil', 'Cucumber & lime' ], 'allergens' => [ 'Egg', 'Soy', 'Fish sauce (fish)', 'Shellfish (prawn option)' ],
		'vegan_note' => 'Choose Vegan and we leave out the egg and fish sauce.',
	],
	[
		'slug' => 'lemongrass-tofu', 'name' => 'Lemongrass Tofu', 'thai' => 'เต้าหู้ตะไคร้', 'roman' => 'Tao Hoo Ta Krai', 'cats' => [ 'wok' ],
		'price' => 1400, 'opts' => [ 'spice' ], 'heat' => 1, 'tags' => [ 'vegetarian', 'vegan' ], 'likes' => '71% liked (7)',
		'reel' => 'https://www.instagram.com/reel/Daizh-7yOzj/',
		'short' => 'Fragrant lemongrass-marinated tofu, made in our kitchen and wok-tossed with Thai herbs and a hint of citrusy heat.',
		'desc' => 'Our own house-made tofu, marinated in lemongrass, then wok-tossed until crisp outside and tender inside with Thai herbs, onion and a little chilli. Light, savoury and seriously satisfying: even the meat-lovers order it again.',
		'portion' => 'One plate, with jasmine rice', 'ingredients' => [ 'House-made tofu', 'Lemongrass', 'Onion', 'Thai herbs', 'Chilli', 'Jasmine rice' ], 'allergens' => [ 'Soy' ],
	],
	[
		'slug' => 'goong-kratiem-garlic-prawns', 'name' => 'Goong Kratiem – Garlic Prawns', 'thai' => 'กุ้งกระเทียม', 'roman' => 'Goong Kratiem', 'cats' => [ 'wok' ],
		'price' => 2250, 'opts' => [ 'spice' ], 'heat' => 1, 'tags' => [ 'seafood' ], 'likes' => '75% liked (4)',
		'short' => 'Jumbo prawns wok-tossed in a rich, buttery garlic sauce with chives, spring onions and coriander root, under a crown of crispy golden garlic.',
		'desc' => 'Succulent jumbo prawns wok-tossed in a rich, buttery garlic sauce with chives, spring onions and coriander root. Finished with a generous topping of golden crispy garlic for a satisfying crunch in every bite.',
		'portion' => 'One plate', 'ingredients' => [ 'Jumbo prawns', 'Garlic', 'Butter', 'Chives & spring onion', 'Coriander root', 'Crispy garlic' ], 'allergens' => [ 'Shellfish', 'Milk (butter)', 'Soy' ],
	],
	[
		'slug' => 'lemongrass-beef-steak', 'name' => 'Lemongrass Beef Steak with Rice', 'thai' => 'เนื้อย่างตะไคร้', 'roman' => 'Neua Yang Ta Krai', 'cats' => [ 'wok' ],
		'price' => 2000, 'opts' => [ 'spice' ], 'heat' => 1, 'tags' => [ 'beef' ],
		'short' => 'Prime cuts of beef marinated in lemongrass, garlic, kaffir lime leaves and Thai spices, grilled and served with rice.',
		'desc' => 'Tender beef marinated in fresh lemongrass, garlic, kaffir lime leaves and Thai spices, grilled to perfection and served with jasmine rice and a fresh salad.',
		'portion' => 'One plate, with jasmine rice', 'ingredients' => [ 'Beef', 'Lemongrass', 'Garlic', 'Kaffir lime leaves', 'Thai spices', 'Jasmine rice' ], 'allergens' => [ 'Soy', 'Fish sauce (fish)' ],
	],
	// Curries
	[
		'slug' => 'green-curry', 'name' => 'Green Curry', 'thai' => 'แกงเขียวหวาน', 'roman' => 'Gaeng Keow Wan', 'cats' => [ 'curries' ],
		'protein' => $protein, 'base_price' => 1500, 'opts' => [ 'spice' ], 'heat' => 2, 'tags' => [ 'chicken', 'seafood', 'vegetarian', 'vegan', 'gluten-free' ], 'likes' => '74% liked (35)', 'badge' => '#3 most liked on Uber Eats',
		'short' => 'Coconut-milk green curry with Thai basil and aubergine, served with jasmine rice. Chicken or tofu, or upgrade to prawns.',
		'desc' => 'Green curry paste fried until fragrant, then simmered in coconut milk with Thai aubergine, basil leaves and your choice of protein. Creamy, aromatic and as hot as you like it. Served with a side of jasmine rice.',
		'portion' => 'One bowl, with jasmine rice', 'ingredients' => [ 'Green curry paste', 'Coconut milk', 'Thai aubergine', 'Thai basil', 'Kaffir lime leaf', 'Jasmine rice' ], 'allergens' => [ 'Coconut', 'Fish sauce (fish)', 'Shellfish (prawn option)' ],
		'vegan_note' => 'Choose Vegan and we leave out the fish sauce.',
	],
	[
		'slug' => 'coconut-fish-curry', 'name' => 'Coconut Fish Curry with Rice', 'thai' => 'แกงปลากะทิ', 'roman' => 'Gaeng Pla Kati', 'cats' => [ 'curries' ],
		'price' => 2000, 'opts' => [ 'spice' ], 'heat' => 1, 'tags' => [ 'seafood', 'gluten-free' ],
		'short' => 'Red sea bass in coconut curry with aromatic lemongrass, served with jasmine rice.',
		'desc' => 'Fillets of red sea bass simmered in a gentle coconut curry with lemongrass and kaffir lime, served with jasmine rice. Fragrant and comforting.',
		'portion' => 'One bowl, with jasmine rice', 'ingredients' => [ 'Red sea bass', 'Coconut milk', 'Curry paste', 'Lemongrass', 'Kaffir lime', 'Jasmine rice' ], 'allergens' => [ 'Fish', 'Coconut' ],
	],
	// Matcha
	[
		'slug' => 'iced-matcha-latte', 'name' => 'Iced Matcha Latte', 'thai' => 'มัทฉะลาเต้เย็น', 'roman' => 'Matcha Latte', 'cats' => [ 'matcha' ],
		'price' => 500, 'opts' => [ 'sweet', 'milk' ], 'tags' => [ 'vegetarian' ], 'clip' => 'matcha', 'badge' => 'The classic',
		'short' => 'Ceremonial-grade matcha whisked fresh and poured over cold milk and ice.',
		'desc' => 'Our everyday favourite: 100% organic ceremonial-grade matcha from Japan, whisked by hand for every cup and poured over chilled milk and ice. Make it less sweet, or swap to oat or coconut milk.',
		'portion' => 'One cup', 'ingredients' => [ 'Ceremonial matcha', 'Milk', 'Ice', 'Cane sugar' ], 'allergens' => [ 'Milk (swap available)' ],
	],
	[
		'slug' => 'ice-matcha-tea', 'name' => 'Iced Matcha Tea', 'thai' => 'ชาเขียวมัทฉะเย็น', 'roman' => 'Matcha Tea', 'cats' => [ 'matcha' ],
		'price' => 500, 'opts' => [ 'sweet' ], 'tags' => [ 'vegan', 'vegetarian' ], 'clip' => 'matcha',
		'short' => 'Strong ceremonial matcha over ice, no milk: earthy, smooth and wide awake.',
		'desc' => 'Refreshingly strong matcha tea served cold: earthy, smooth and invigorating, to wake you up and keep you focused. Just ceremonial-grade matcha, water and ice.',
		'portion' => 'One cup', 'ingredients' => [ 'Ceremonial matcha', 'Water', 'Ice' ], 'allergens' => [],
	],
	[
		'slug' => 'strawberry-matcha-latte', 'name' => 'Iced Strawberry Matcha Latte', 'thai' => 'มัทฉะสตรอว์เบอร์รี', 'roman' => 'Strawberry Matcha', 'cats' => [ 'matcha' ],
		'price' => 700, 'opts' => [ 'sweet', 'milk' ], 'tags' => [ 'vegetarian' ], 'clip' => 'matcha', 'badge' => 'Most photographed',
		'reel' => 'https://www.instagram.com/reel/DWi71jBkn7O/',
		'short' => 'Strawberry purée, cold milk and a float of ceremonial matcha: pink, green and gone in minutes.',
		'desc' => 'Layers of strawberry, chilled milk and freshly whisked ceremonial matcha, served iced. Sweet, fruity and energising.',
		'portion' => 'One cup', 'ingredients' => [ 'Ceremonial matcha', 'Strawberry', 'Milk', 'Ice' ], 'allergens' => [ 'Milk (swap available)' ],
	],
	[
		'slug' => 'dirty-matcha-latte', 'name' => 'Dirty Iced Matcha Latte', 'thai' => 'มัทฉะดำ', 'roman' => 'Dirty Thai Matcha', 'cats' => [ 'matcha' ],
		'price' => 700, 'opts' => [ 'sweet', 'milk' ], 'tags' => [ 'vegetarian' ], 'clip' => 'matcha',
		'short' => 'Iced matcha latte with a shot of rich Thai coffee: matcha on top, coffee below.',
		'desc' => 'For when one caffeine isn\'t enough. An iced matcha latte layered over strong Thai coffee for a unique, refreshing twist.',
		'portion' => 'One cup', 'ingredients' => [ 'Ceremonial matcha', 'Thai coffee', 'Milk', 'Ice' ], 'allergens' => [ 'Milk (swap available)' ],
	],
	[
		'slug' => 'black-sesame-matcha', 'name' => 'Black Sesame Matcha', 'thai' => 'มัทฉะงาดำ', 'roman' => 'Black Sesame Matcha', 'cats' => [ 'matcha' ],
		'price' => 600, 'opts' => [ 'sweet', 'milk' ], 'tags' => [ 'vegetarian' ], 'clip' => 'matcha',
		'reel' => 'https://www.instagram.com/reel/DNbiBxug-yC/',
		'short' => 'Creamy, nutty black sesame meets earthy ceremonial matcha, sweetened with honey.',
		'desc' => 'Creamy, nutty black sesame meets the smooth earthiness of our organic ceremonial-grade matcha from Japan, sweetened with honey. Bold yet balanced: comfort and energy in one cup.',
		'portion' => 'One cup', 'ingredients' => [ 'Ceremonial matcha', 'Black sesame', 'Honey', 'Milk', 'Ice' ], 'allergens' => [ 'Sesame', 'Milk (swap available)' ],
	],
	[
		'slug' => 'ube-matcha', 'name' => 'Ube Matcha', 'thai' => 'อูเบะมัทฉะ', 'roman' => 'Ube Matcha', 'cats' => [ 'matcha' ],
		'price' => 600, 'opts' => [ 'sweet', 'milk' ], 'tags' => [ 'vegetarian' ], 'clip' => 'matcha',
		'short' => 'Purple yam (ube) cream over ceremonial matcha: vibrant, earthy and sweet.',
		'desc' => 'A unique blend of ube (purple yam) and ceremonial matcha for a vibrant, earthy drink. As good to look at as it is to drink.',
		'portion' => 'One cup', 'ingredients' => [ 'Ceremonial matcha', 'Ube (purple yam)', 'Milk', 'Ice' ], 'allergens' => [ 'Milk (swap available)' ],
	],
	[
		'slug' => 'coconut-cloud-matcha', 'name' => 'Matcha Coconut Cloud', 'thai' => 'มัทฉะมะพร้าว', 'roman' => 'Coconut Cloud Matcha', 'cats' => [ 'matcha' ],
		'price' => 700, 'opts' => [ 'sweet' ], 'tags' => [ 'vegetarian' ], 'clip' => 'matcha',
		'short' => 'Creamy matcha cloud floating over cold coconut water: light, smooth and tropical.',
		'desc' => 'A creamy matcha foam floating over refreshing coconut water. Light, smooth and refreshing, with a touch of indulgence.',
		'portion' => 'One cup', 'ingredients' => [ 'Ceremonial matcha', 'Coconut water', 'Cream', 'Ice' ], 'allergens' => [ 'Coconut', 'Milk' ],
	],
	[
		'slug' => 'blueberry-matcha', 'name' => 'Blueberry Matcha', 'thai' => 'มัทฉะบลูเบอร์รี', 'roman' => 'Blueberry Matcha', 'cats' => [ 'matcha' ],
		'price' => 700, 'placeholder_price' => true, 'opts' => [ 'sweet', 'milk' ], 'tags' => [ 'vegetarian' ], 'clip' => 'matcha',
		'reel' => 'https://www.instagram.com/reel/DZFSOiJyIs9/',
		'short' => 'Where Japan\'s matcha tradition meets a burst of sweet blueberry. Smooth, creamy and refreshing.',
		'desc' => 'Blueberry compote, chilled milk and ceremonial matcha, layered over ice. Smooth, creamy, refreshing, and packed with matcha flavour in every sip.',
		'portion' => 'One cup', 'ingredients' => [ 'Ceremonial matcha', 'Blueberry', 'Milk', 'Ice' ], 'allergens' => [ 'Milk (swap available)' ],
	],
	[
		'slug' => 'pineapple-matcha', 'name' => 'Pineapple Matcha', 'thai' => 'มัทฉะสับปะรด', 'roman' => 'Pineapple Matcha', 'cats' => [ 'matcha' ],
		'price' => 700, 'placeholder_price' => true, 'opts' => [ 'sweet' ], 'tags' => [ 'vegan', 'vegetarian' ], 'clip' => 'matcha',
		'reel' => 'https://www.instagram.com/reel/DQH2Vbckrpy/',
		'short' => 'Fresh pineapple juice topped with ceremonial matcha: tropical, bright and dairy-free.',
		'desc' => 'Fresh pineapple juice over ice, crowned with freshly whisked ceremonial matcha. Bright, tropical and dairy-free.',
		'portion' => 'One cup', 'ingredients' => [ 'Ceremonial matcha', 'Fresh pineapple juice', 'Ice' ], 'allergens' => [],
	],
	// Thai teas, coffee & coolers
	[
		'slug' => 'thai-iced-tea', 'name' => 'Classic Thai Iced Tea', 'thai' => 'ชาเย็น', 'roman' => 'Cha Yen', 'cats' => [ 'drinks' ],
		'price' => 600, 'opts' => [ 'sweet' ], 'tags' => [ 'vegetarian' ], 'likes' => '83% liked (18)', 'badge' => 'Crowd favourite',
		'reel' => 'https://www.instagram.com/reel/DZC21aDSJCX/',
		'short' => 'Sweet, creamy, bright-orange Thai tea, brewed from authentic Thai tea leaves and served over ice.',
		'desc' => 'Made with authentic Thai tea leaves, brewed strong and served just the way it\'s enjoyed in Thailand: sweet, creamy and incredibly refreshing.',
		'portion' => 'One cup', 'ingredients' => [ 'Thai tea leaves', 'Condensed & evaporated milk', 'Ice' ], 'allergens' => [ 'Milk' ],
	],
	[
		'slug' => 'thai-iced-green-tea', 'name' => 'Thai Iced Green Tea', 'thai' => 'ชาเขียวเย็น', 'roman' => 'Cha Khiao Yen', 'cats' => [ 'drinks' ],
		'price' => 500, 'opts' => [ 'sweet' ], 'tags' => [ 'vegetarian' ],
		'short' => 'Refreshing Thai milk tea brewed with green tea leaves.',
		'desc' => 'Thailand\'s other iced tea: fragrant Thai green tea, sweetened and creamy, over ice.',
		'portion' => 'One cup', 'ingredients' => [ 'Thai green tea', 'Milk', 'Ice' ], 'allergens' => [ 'Milk' ],
	],
	[
		'slug' => 'thai-iced-coffee', 'name' => 'Thai Iced Coffee', 'thai' => 'กาแฟเย็น', 'roman' => 'Oliang', 'cats' => [ 'drinks' ],
		'price' => 500, 'opts' => [ 'sweet' ], 'tags' => [ 'vegetarian' ],
		'short' => 'Rich, creamy coffee with a hint of sweetness, served chilled the Thai way.',
		'desc' => 'Strong Thai coffee, sweetened and creamy, served over ice for a refreshing Thai twist.',
		'portion' => 'One cup', 'ingredients' => [ 'Thai coffee', 'Milk', 'Ice' ], 'allergens' => [ 'Milk' ],
	],
	[
		'slug' => 'thai-coconut-iced-coffee', 'name' => 'Thai Coconut Iced Coffee', 'thai' => 'กาแฟมะพร้าว', 'roman' => 'Kafae Maprao', 'cats' => [ 'drinks' ],
		'price' => 600, 'opts' => [ 'sweet' ], 'tags' => [ 'vegetarian' ],
		'short' => 'Rich and creamy iced coffee infused with coconut.',
		'desc' => 'Our Thai coffee with creamy coconut, served over ice.',
		'portion' => 'One cup', 'ingredients' => [ 'Thai coffee', 'Coconut milk', 'Ice' ], 'allergens' => [ 'Coconut' ],
	],
	[
		'slug' => 'vietnamese-iced-coffee', 'name' => 'Vietnamese Iced Coffee', 'thai' => 'กาแฟเวียดนาม', 'roman' => 'Cà Phê Sữa Đá', 'cats' => [ 'drinks' ],
		'price' => 500, 'opts' => [ 'sweet' ], 'tags' => [ 'vegetarian' ],
		'short' => 'Rich, smooth drip coffee with condensed milk, served over ice.',
		'desc' => 'Strong, smooth coffee with sweet condensed milk, served over ice the Vietnamese way.',
		'portion' => 'One cup', 'ingredients' => [ 'Coffee', 'Condensed milk', 'Ice' ], 'allergens' => [ 'Milk' ],
	],
	[
		'slug' => 'coconut-cloud-coffee', 'name' => 'Coconut Cloud Coffee', 'thai' => 'กาแฟมะพร้าวเมฆ', 'roman' => 'Coconut Coffee Cloud', 'cats' => [ 'drinks' ],
		'price' => 600, 'tags' => [ 'vegetarian' ],
		'short' => 'A creamy coffee cloud over cold coconut water: a smooth, tropical caffeine fix.',
		'desc' => 'Creamy whipped coffee floating over coconut water, offering a smooth and tropical twist to your caffeine fix.',
		'portion' => 'One cup', 'ingredients' => [ 'Coffee', 'Coconut water', 'Cream' ], 'allergens' => [ 'Milk', 'Coconut' ],
	],
	[
		'slug' => 'coconut-cloud-ube', 'name' => 'Ube Coconut Cloud', 'thai' => 'อูเบะมะพร้าว', 'roman' => 'Ube Coconut Cloud', 'cats' => [ 'drinks' ],
		'price' => 700, 'tags' => [ 'vegetarian' ], 'badge' => 'Viral on Instagram',
		'reel' => 'https://www.instagram.com/reel/DNbJ1qMs1Lq/',
		'short' => 'A dreamy purple ube cream floating over refreshing coconut water. Light, smooth and tropical.',
		'desc' => 'Our most-watched creation: a creamy ube (purple yam) top floating over cold coconut water. Light, smooth and tropical with a touch of indulgence.',
		'portion' => 'One cup', 'ingredients' => [ 'Ube (purple yam)', 'Cream', 'Coconut water' ], 'allergens' => [ 'Milk', 'Coconut' ],
	],
	[
		'slug' => 'vietnamese-ube', 'name' => 'Vietnamese Coffee Ube', 'thai' => 'กาแฟเวียดนามอูเบะ', 'roman' => 'Vietnamese Ube', 'cats' => [ 'drinks' ],
		'price' => 700, 'tags' => [ 'vegetarian' ],
		'short' => 'Vietnamese iced coffee under a purple ube cream: sweet, earthy and strong.',
		'desc' => 'A unique purple yam drink inspired by Vietnamese flavours: strong Vietnamese coffee topped with creamy ube.',
		'portion' => 'One cup', 'ingredients' => [ 'Vietnamese coffee', 'Ube cream', 'Condensed milk', 'Ice' ], 'allergens' => [ 'Milk' ],
	],
	[
		'slug' => 'iced-taro-frappe', 'name' => 'Iced Taro Frappe', 'thai' => 'เผือกปั่น', 'roman' => 'Pueak Pan', 'cats' => [ 'drinks' ],
		'price' => 600, 'opts' => [ 'sweet' ], 'tags' => [ 'vegetarian' ], 'likes' => '100% liked (4)',
		'short' => 'Creamy taro blended with ice: a nutty, lilac-coloured treat.',
		'desc' => 'Creamy taro root blended with milk and ice into a thick, refreshing frappe.',
		'portion' => 'One cup', 'ingredients' => [ 'Taro', 'Milk', 'Ice' ], 'allergens' => [ 'Milk' ],
	],
	[
		'slug' => 'lemongrass-iced-tea', 'name' => 'Lemongrass Iced Tea', 'thai' => 'ชาตะไคร้เย็น', 'roman' => 'Cha Ta Krai', 'cats' => [ 'drinks' ],
		'price' => 500, 'opts' => [ 'sweet' ], 'tags' => [ 'vegan', 'vegetarian' ],
		'short' => 'Fresh lemongrass brewed and chilled over ice. Caffeine-free and dairy-free.',
		'desc' => 'Fresh lemongrass brewed into a fragrant herbal tea and served over ice.',
		'portion' => 'One cup', 'ingredients' => [ 'Fresh lemongrass', 'Cane sugar', 'Ice' ], 'allergens' => [],
	],
	[
		'slug' => 'lemongrass-tea-local-lemon', 'name' => 'Lemongrass Tea with Local Lemon', 'thai' => 'ชาตะไคร้มะนาว', 'roman' => 'Cha Ta Krai Manao', 'cats' => [ 'drinks' ],
		'price' => 500, 'opts' => [ 'sweet' ], 'tags' => [ 'vegan', 'vegetarian' ], 'drawn' => true,
		'short' => 'Refreshing herbal lemongrass tea with a citrus lift from local lemon.',
		'desc' => 'Fresh lemongrass tea brightened with Kenyan lemon. Caffeine-free.',
		'portion' => 'One cup', 'ingredients' => [ 'Fresh lemongrass', 'Local lemon' ], 'allergens' => [],
	],
	[
		'slug' => 'coconut-water', 'name' => 'Coconut Water', 'thai' => 'น้ำมะพร้าว', 'roman' => 'Nam Maprao', 'cats' => [ 'drinks' ],
		'price' => 400, 'tags' => [ 'vegan', 'vegetarian' ], 'drawn' => true,
		'short' => 'Refreshing, hydrating coconut water: nature\'s thirst-quencher.',
		'desc' => 'Fresh coconut water, chilled.',
		'portion' => 'One serving', 'ingredients' => [ 'Coconut water' ], 'allergens' => [ 'Coconut' ],
	],
	[
		'slug' => 'bottled-water', 'name' => 'Bottled Water (500 ml)', 'thai' => 'น้ำดื่ม', 'roman' => 'Nam Plao', 'cats' => [ 'drinks' ],
		'price' => 100, 'tags' => [ 'vegan', 'vegetarian' ], 'drawn' => true,
		'short' => 'Still mineral water, 500 ml.', 'desc' => 'Still bottled water, 500 ml.',
		'portion' => '500 ml', 'ingredients' => [ 'Water' ], 'allergens' => [],
	],
	[
		'slug' => 'sparkling-water', 'name' => 'Sparkling Water (500 ml)', 'thai' => 'น้ำโซดา', 'roman' => 'Nam Soda', 'cats' => [ 'drinks' ],
		'price' => 200, 'tags' => [ 'vegan', 'vegetarian' ], 'drawn' => true,
		'short' => 'Sparkling water, 500 ml.', 'desc' => 'Sparkling bottled water, 500 ml.',
		'portion' => '500 ml', 'ingredients' => [ 'Sparkling water' ], 'allergens' => [],
	],
	// Desserts
	[
		'slug' => 'mango-sticky-rice', 'name' => 'Mango Sticky Rice', 'thai' => 'ข้าวเหนียวมะม่วง', 'roman' => 'Khao Niao Mamuang', 'cats' => [ 'desserts' ],
		'price' => 900, 'tags' => [ 'vegan', 'vegetarian', 'gluten-free' ], 'likes' => '68% liked (19)', 'badge' => 'The perfect ending',
		'reel' => 'https://www.instagram.com/reel/DZ5h9YtSJrR/',
		'short' => 'Sweet coconut sticky rice with ripe mango slices: the perfect ending to a Thai feast.',
		'desc' => 'Glutinous rice steamed and soaked in sweet coconut cream, served with slices of ripe mango and a drizzle of coconut sauce. Ask for the butterfly-pea version: the rice turns a deep blue.',
		'portion' => 'One serving', 'ingredients' => [ 'Sticky rice', 'Coconut cream', 'Ripe mango', 'Sesame or mung beans' ], 'allergens' => [ 'Coconut', 'May contain sesame' ],
	],
	// Lunch deal (their own offer, Instagram 26 Jul 2026)
	[
		'slug' => 'corporate-lunch', 'name' => 'Corporate Lunch: Thai Fried Rice + Juice', 'thai' => 'ข้าวกลางวัน', 'roman' => 'Lunch Set', 'cats' => [ 'deals' ],
		'price' => 1000, 'opts' => [ 'spice' ], 'heat' => 1, 'tags' => [ 'chicken' ], 'badge' => 'Monday – Thursday',
		'short' => 'Thai fried rice and a juice of the day for KSh 1,000. Monday to Thursday, for offices, meetings and busy professionals.',
		'desc' => 'A delicious break for a productive day: our Thai fried rice with a fresh juice of the day, for KSh 1,000. Valid Monday to Thursday, for dine-in, pickup or office delivery. Ordering for a whole team? WhatsApp 0742 093080 and we\'ll deliver on time.',
		'portion' => 'One plate + one juice', 'ingredients' => [ 'Thai fried rice', 'Fresh juice of the day' ], 'allergens' => [ 'Egg', 'Soy', 'Fish sauce (fish)' ],
	],
];

function kt_attach_images( $pid, $slug, $name ) {
	$num   = fn( $f ) => preg_match( '#^' . preg_quote( $slug, '#' ) . '(?:-(\d+))?\.webp$#', basename( $f ), $m ) ? (int) ( $m[1] ?? 1 ) : 0;
	$files = array_values( array_filter( glob( "/wordpress/kt-images/$slug*.webp" ) ?: [], fn( $f ) => $num( $f ) > 0 ) );
	usort( $files, fn( $a, $b ) => $num( $a ) <=> $num( $b ) );
	$ids = [];
	foreach ( $files as $file ) {
		$base = basename( $file );
		$tmp  = wp_tempnam( $base );
		copy( $file, $tmp );
		$n   = $num( $file );
		$att = kt_sideload( $tmp, $base, $pid, $name . ( $n > 1 ? ' – photo ' . $n : '' ) );
		if ( ! is_wp_error( $att ) ) $ids[ $n ] = $att;
	}
	return $ids; // photo number => attachment id
}

function kt_local_attr( $name, array $options ) {
	$a = new WC_Product_Attribute();
	$a->set_name( $name );
	$a->set_options( $options );
	$a->set_position( 0 );
	$a->set_visible( true );
	$a->set_variation( true );
	return $a;
}

// Most-ordered dishes go first on the home page's "Signature" row.
$signature = [ 'pad-thai', 'pad-kra-pao-gai', 'green-curry', 'papaya-salad', 'strawberry-matcha-latte', 'coconut-cloud-ube', 'mango-sticky-rice', 'thai-bbq-chicken-wings' ];

foreach ( $products as $order => $d ) {
	$choices  = $d['protein'] ?? $d['sizes'] ?? null;
	$p = $choices ? new WC_Product_Variable() : new WC_Product_Simple();
	$p->set_name( $d['name'] );
	$p->set_slug( $d['slug'] );
	$p->set_status( 'publish' );
	$p->set_menu_order( $order );
	$p->set_description( $d['desc'] );
	$p->set_short_description( $d['short'] );
	$p->set_category_ids( array_map( fn( $c ) => $cat[ $c ], $d['cats'] ) );
	$p->set_tag_ids( array_map( fn( $t ) => $tags[ $t ], $d['tags'] ?? [] ) );
	$p->set_featured( in_array( $d['slug'], $signature, true ) );
	$meta = [
		'thai'        => $d['thai'] ?? '',
		'roman'       => $d['roman'] ?? '',
		'opts'        => implode( ',', $d['opts'] ?? [] ),
		'heat'        => (string) ( $d['heat'] ?? 0 ),
		'likes'       => $d['likes'] ?? '',
		'badge'       => $d['badge'] ?? '',
		'portion'     => $d['portion'] ?? '',
		'ingredients' => implode( "\n", $d['ingredients'] ?? [] ),
		'allergens'   => implode( "\n", $d['allergens'] ?? [] ),
		'vegan_note'  => $d['vegan_note'] ?? '',
		'clip'        => $d['clip'] ?? '',
		'reel'        => $d['reel'] ?? '',
	];
	foreach ( $meta as $k => $v ) {
		if ( $v !== '' ) $p->update_meta_data( '_kt_' . $k, $v );
	}
	if ( ! empty( $d['drawn'] ) ) $p->update_meta_data( '_kt_drawn', 'yes' );
	if ( ! empty( $d['placeholder_price'] ) ) $p->update_meta_data( '_kt_price_to_confirm', 'yes' );

	if ( $choices ) {
		$attr = isset( $d['protein'] ) ? 'Protein' : 'Size';
		$p->set_attributes( [ kt_local_attr( $attr, array_keys( $choices ) ) ] );
		$p->set_default_attributes( [ sanitize_title( $attr ) => array_key_first( $choices ) ] );
	} else {
		$p->set_regular_price( (string) $d['price'] );
	}
	$pid = $p->save();

	$imgs = kt_attach_images( $pid, $d['slug'], $d['name'] );
	if ( $imgs ) {
		// Written as meta: a second full product save here slowed the import.
		set_post_thumbnail( $pid, $imgs[1] ?? reset( $imgs ) );
		update_post_meta( $pid, '_product_image_gallery', implode( ',', array_values( array_diff_key( $imgs, [ 1 => true ] ) ) ) );
	}

	if ( $choices ) {
		// Protein: base price + upgrade (Prawns +300, as on their Uber Eats menu). Sizes: their own prices.
		$base = $d['base_price'] ?? 0;
		$n    = 0;
		foreach ( $choices as $label => $amount ) {
			$v = new WC_Product_Variation();
			$v->set_parent_id( $pid );
			$v->set_attributes( [ sanitize_title( $attr ) => $label ] );
			$v->set_regular_price( (string) ( $base + $amount ) );
			$v->set_menu_order( $n++ );
			$v->save();
		}
		WC_Product_Variable::sync( $pid );
	}
}

// Thai Friday: 10% off everything on Fridays (their own offer, Instagram 3 Sep 2026; still running? PLACEHOLDER).
// The theme only accepts the code on Fridays.
if ( ! wc_get_coupon_id_by_code( 'THAIFRIDAY' ) ) {
	$c = new WC_Coupon();
	$c->set_code( 'THAIFRIDAY' );
	$c->set_description( 'Thai Friday: 10% off everything on the menu, Fridays only.' );
	$c->set_discount_type( 'percent' );
	$c->set_amount( 10 );
	$c->save();
}

// Pages.
function kt_page( $slug, $title, $content ) {
	$existing = get_page_by_path( $slug );
	if ( $existing ) return $existing->ID;
	return wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content ] );
}
kt_page( 'menu', 'Order online', "<!-- wp:paragraph -->\n<p>Every dish is wok-fired, pounded or whisked to order. Filter by craving, diet, spice or budget, set the heat on your Pad Thai, and order for delivery or kerbside pickup, or straight on WhatsApp.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:shortcode -->\n[kt_menu]\n<!-- /wp:shortcode -->" );
kt_page( 'reservations', 'Reserve a table', "<!-- wp:paragraph -->\n<p>Garden tables under the lanterns go first on Friday and Saturday evenings. Pick a date, a time and how many of you are coming. We confirm on WhatsApp within minutes during opening hours.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:shortcode -->\n[kt_reserve_form]\n<!-- /wp:shortcode -->" );
kt_page( 'about', 'About us', '' ); // content lives in the theme: templates/page-about.html
kt_page( 'faqs', 'FAQs & dining guide', '' ); // templates/page-faqs.html
kt_page( 'events', 'Events & private dining', "<!-- wp:paragraph -->\n<p>Birthdays, team lunches, date nights and celebrations in our leafy Westlands garden, or Thai food and matcha brought to your office. Tell us the date and the group, and we'll put together a menu and a quote.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:shortcode -->\n[kt_event_form]\n<!-- /wp:shortcode -->" );

// Cart and checkout read as an order.
foreach ( [ 'shop' => 'Full menu', 'cart' => 'Your order', 'checkout' => 'Checkout' ] as $page => $title ) {
	$id = wc_get_page_id( $page );
	if ( $id > 0 ) wp_update_post( [ 'ID' => $id, 'post_title' => $title ] );
}

update_option( 'permalink_structure', '/%postname%/' );
flush_rewrite_rules();

// Skip WooCommerce's first-run redirect and setup checklist so the admin opens on the store itself.
delete_transient( '_wc_activation_redirect' );
update_option( 'woocommerce_task_list_hidden_lists', [ 'setup', 'extended' ] );
update_option( 'woocommerce_task_list_complete', 'yes' );
update_option( 'woocommerce_show_marketplace_suggestions', 'no' );
update_option( 'woocommerce_admin_install_timestamp', time() - WEEK_IN_SECONDS );

$wpdb->query( 'COMMIT' );
wp_suspend_cache_invalidation( false );
wp_defer_term_counting( false );

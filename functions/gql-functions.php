<?php
/*
 * Expose theme screenshot to WP-GQL API
 */
function whitelist_settings()
{
    // Define a field to get Theme screenshot URL
    register_graphql_field("GeneralSettings", "themeScreenshotUrl", [
        "type" => "String",
        "description" => __("URL to the active theme screenshot", "fuxt"),
        "resolve" => function ($root, $args, $context, $info) {
            $theme = wp_get_theme();
            $url = "";
            if ($theme->screenshot) {
                $url = get_theme_file_uri($theme->screenshot);
            }
            return $url;
        },
    ]);

    // Define a fields to get both WordPress URLs
    register_graphql_field("GeneralSettings", "backendUrl", [
        "type" => "String",
        "description" => __("WordPress Address (URL)", "fuxt"),
        "resolve" => function ($root, $args, $context, $info) {
            return get_site_url();
        },
    ]);
    register_graphql_field("GeneralSettings", "frontendUrl", [
        "type" => "String",
        "description" => __("Site Address (URL)", "fuxt"),
        "resolve" => function ($root, $args, $context, $info) {
            return get_home_url();
        },
    ]);
}
add_action("graphql_init", "whitelist_settings", 1);

/*
 * Give media items a `html` field that outputs the SVG element or an IMG element.
 * SEE https://github.com/wp-graphql/wp-graphql/issues/1035
 */
function fuxt_add_media_element()
{
    // Add content field for media item
    register_graphql_field("mediaItem", "element", [
        "type" => "String",
        "resolve" => function ($source, $args) {
            if ($source->mimeType == "image/svg+xml") {
                $media_file = get_attached_file($source->ID);
                if ($media_file) {
                    $svg_file_content = file_get_contents($media_file);

                    $find_string = "<svg";
                    $position = strpos($svg_file_content, $find_string);

                    return trim(substr($svg_file_content, $position));
                } else {
                    return "File is missing";
                }
            } else {
                return wp_get_attachment_image($source->ID, "full");
            }
        },
    ]);
}
add_action("graphql_register_types", "fuxt_add_media_element");

/*
 * Give each content node a field of HTML encoded to play nicely with wp-content Vue component
 * SEE https://github.com/wp-graphql/wp-graphql/issues/1035
 */
function add_encoded_content_field()
{
    register_graphql_field("NodeWithContentEditor", "encodedContent", [
        "type" => "String",
        "resolve" => function ($post) {
            $content = get_post($post->databaseId)->post_content;
            return !empty($content)
                ? apply_filters("the_content", $content)
                : null;
        },
    ]);
}
add_action("graphql_register_types", "add_encoded_content_field");

/*
 * Make menus publicly accessible
 */
function enable_public_menus(
    $is_private,
    $model_name,
    $data,
    $visibility,
    $owner,
    $current_user
) {
    if ("MenuObject" === $model_name || "MenuItemObject" === $model_name) {
        return false;
    }
    return $is_private;
}
add_filter("graphql_data_is_private", "enable_public_menus", 10, 6);

/*
 * This allows any frontend domain to access the GQL endpoint. This mirros how WP-JSON API works.
 * SEE https://developer.wordpress.org/reference/functions/rest_send_cors_headers/
 * SEE https://github.com/funkhaus/wp-graphql-cors/blob/master/includes/process-request.php
 */
function set_wpgql_cors_response_headers($headers)
{
    // Abort if using Wp-GQL_CORS plugin for headers instead
    if (class_exists("WP_GraphQL_CORS")) {
        return $headers;
    }

    // Set site url as allowed origin.
    $allowed_origins = array(
        site_url(),
    );

    // Add fuxt home url to allowed origin.
    $fuxt_home_url = get_option( 'fuxt_home_url' );
    if ( $fuxt_home_url ) {
        $allowed_origins[] = $fuxt_home_url;
    }

    $allowed_origins = apply_filters( 'fuxt_allowed_origins', $allowed_origins );

    // Consider localhost case.
    $origin        = get_http_origin();
    $parsed_origin = wp_parse_url( $origin );

    if ( ! in_array( $origin, $allowed_origins, true ) && 'localhost' !== $parsed_origin['host'] ) {
        $origin = $allowed_origins[0];
    }

    $headers["Access-Control-Allow-Origin"]      = $origin;
    $headers["Access-Control-Allow-Credentials"] = "true";

    // Allow certain header types. Respect the defauls from WP-GQL too.
    $access_control_allow_headers = apply_filters(
        "graphql_access_control_allow_headers",
        ["Authorization", "Content-Type", "Preview"]
    );
    $headers["Access-Control-Allow-Headers"] = implode(
        ", ",
        $access_control_allow_headers
    );

    return $headers;
}
add_filter(
    "graphql_response_headers_to_send",
    "set_wpgql_cors_response_headers"
);

/*
 * Adds next post node to all the custom Post Types
 */
function gql_register_next_post()
{
    $post_types = WPGraphQL::get_allowed_post_types();

    if (!empty($post_types) && is_array($post_types)) {
        foreach ($post_types as $post_type) {
            $post_type_object = get_post_type_object($post_type);

            // Get the Type name with ucfirst
            $ucfirst = ucfirst($post_type_object->graphql_single_name);

            // Register a new Edge Type
            register_graphql_type("Next" . $ucfirst . "Edge", [
                "fields" => [
                    "node" => [
                        "description" => __(
                            "The node of the next item",
                            "wp-graphql"
                        ),
                        "type" => $ucfirst,
                        "resolve" => function ($post_id, $args, $context) {
                            return \WPGraphQL\Data\DataSource::resolve_post_object(
                                $post_id,
                                $context
                            );
                        },
                    ],
                ],
            ]);

            // Register the next{$type} field
            register_graphql_field($ucfirst, "next" . $ucfirst, [
                "type" => "Next" . $ucfirst . "Edge",
                "description" => __(
                    "The next post of the current port",
                    "wp-graphql"
                ),
                "args" => [
                    'inSameTerm' => [
                        'type'        => 'Boolean',
                        'description' => __( 'Whether post should be in a same taxonomy term. Default value: false', 'wp-graphql' ),
                        'default'     => false,
                    ],
                    'taxonomy' => [
                        'type' => 'String',
                        'description' => __( 'Taxonomy, if inSameTerm is true', 'wp-graphql' ),
                    ],
                    'termNotIn' => [
                        'type' => 'String',
                        'description' => __( 'Comma-separated list of excluded term IDs.', 'wp-graphql' ),
                    ],
                    'termSlugNotIn' => [
                        'type' => 'String',
                        'description' => __( 'Comma-separated list of excluded term slugs.', 'wp-graphql' ),
                    ],
                ],
                "resolve" => function ($post, $args, $context) {
                    $post = get_post($post->ID);

                    $in_same_term        = isset( $args['inSameTerm'] ) ? $args['inSameTerm'] : false;
                    $excluded_terms      = isset( $args['termNotIn'] ) ? array_map( 'intval', explode( ',', $args['termNotIn'] ) ) : array();
                    $taxonomy            = isset( $args['taxonomy'] ) ? $args['taxonomy'] : 'category';
                    $excluded_term_slugs = isset( $args['termSlugNotIn'] ) ? explode( ',', $args['termSlugNotIn'] ) : array();

                    // merge termNotIn and termSlugNotIn.
                    if ( ! empty( $excluded_term_slugs ) ) {
                        $term_ids = array_map(
                            function( $slug ) use ( $taxonomy ) {
                                $term = get_term_by( 'slug', $slug, $taxonomy );
                                if ( $term ) {
                                    return $term->term_id;
                                }
                                return false;
                            },
                            $excluded_term_slugs
                        );

                        $excluded_terms = array_merge( $excluded_terms, array_filter( $term_ids ) );
                    }

                    $next_post = get_next_post(
                        $in_same_term,
                        $excluded_terms,
                        $taxonomy
                    );

                    return $next_post ? $next_post->ID : null;
                },
            ]);
        }
    }
}
add_action("graphql_register_types", "gql_register_next_post");

/*
 * Adds previous post node to all the custom Post Types
 */
function gql_register_previous_post()
{
    $post_types = WPGraphQL::get_allowed_post_types();

    if (!empty($post_types) && is_array($post_types)) {
        foreach ($post_types as $post_type) {
            $post_type_object = get_post_type_object($post_type);

            // Get the Type name with ucfirst
            $ucfirst = ucfirst($post_type_object->graphql_single_name);

            // Register a new Edge Type
            register_graphql_type("Previous" . $ucfirst . "Edge", [
                "fields" => [
                    "node" => [
                        "description" => __(
                            "The node of the previous item",
                            "wp-graphql"
                        ),
                        "type" => $ucfirst,
                        "resolve" => function ($post_id, $args, $context) {
                            return \WPGraphQL\Data\DataSource::resolve_post_object(
                                $post_id,
                                $context
                            );
                        },
                    ],
                ],
            ]);

            // Register the next{$type} field
            register_graphql_field($ucfirst, "previous" . $ucfirst, [
                "type" => "Previous" . $ucfirst . "Edge",
                "description" => __(
                    "The previous post of the current post",
                    "wp-graphql"
                ),
                "args" => [
                    'inSameTerm' => [
                        'type'        => 'Boolean',
                        'description' => __( 'Whether post should be in a same taxonomy term. Default value: false', 'wp-graphql' ),
                        'default'     => false,
                    ],
                    'taxonomy' => [
                        'type' => 'String',
                        'description' => __( 'Taxonomy, if inSameTerm is true', 'wp-graphql' ),
                    ],
                    'termNotIn' => [
                        'type' => 'String',
                        'description' => __( 'Comma-separated list of excluded term IDs.', 'wp-graphql' ),
                    ],
                    'termSlugNotIn' => [
                        'type' => 'String',
                        'description' => __( 'Comma-separated list of excluded term slugs.', 'wp-graphql' ),
                    ],
                ],
                "resolve" => function ($post, $args, $context) {
                    $post = get_post($post->ID);

                    // Prepare arguments
                    $in_same_term        = isset( $args['inSameTerm'] ) ? $args['inSameTerm'] : false;
                    $excluded_terms      = isset( $args['termNotIn'] ) ? array_map( 'intval', explode( ',', $args['termNotIn'] ) ) : array();
                    $taxonomy            = isset( $args['taxonomy'] ) ? $args['taxonomy'] : 'category';
                    $excluded_term_slugs = isset( $args['termSlugNotIn'] ) ? explode( ',', $args['termSlugNotIn'] ) : array();

                    // merge termNotIn and termSlugNotIn.
                    if ( ! empty( $excluded_term_slugs ) ) {
                        $term_ids = array_map(
                            function( $slug ) use ( $taxonomy ) {
                                $term = get_term_by( 'slug', $slug, $taxonomy );
                                if ( $term ) {
                                    return $term->term_id;
                                }
                                return false;
                            },
                            $excluded_term_slugs
                        );

                        $excluded_terms = array_merge( $excluded_terms, array_filter( $term_ids ) );
                    }

                    $prev_post = get_previous_post(
                        $in_same_term,
                        $excluded_terms,
                        $taxonomy
                    );

                    return $prev_post ? $prev_post->ID : null;
                },
            ]);
        }
    }
}
add_action("graphql_register_types", "gql_register_previous_post");

/*
 * Allow for more than 100 posts/pages to be returned.
 * There is probably a better way to do what you are doing than changing this!
 */
function allow_more_posts_per_query($amount, $source, $args, $context, $info)
{
    return 300;
}
//add_filter( 'graphql_connection_max_query_amount', 'allow_more_posts_per_query', 10, 5);

/*
 * Set the default ordering of quieres in WP-GQL
 */
function custom_default_where_args($query_args, $source, $args, $context, $info)
{
    $post_types = $query_args["post_type"];
    $gql_args = $query_args["graphql_args"];

    if (isset($gql_args["where"])) {
        // Where args set, so use them
        return $query_args;
    } elseif (
        is_array($post_types) &&
        count($post_types) == 1 &&
        in_array("post", $post_types)
    ) {
        // Is just Posts, so use defaults
        return $query_args;
    }

    // Is anything else, so set to menu_order
    $query_args["orderby"] = "menu_order";
    $query_args["order"] = "ASC";
    return $query_args;
}
add_filter(
    "graphql_post_object_connection_query_args",
    "custom_default_where_args",
    10,
    5
);

/*
 * Extend GraphQL to add a mutation to send emails via the wp_mail() function.
 * SEE https://developer.wordpress.org/reference/functions/wp_mail/ for more info on how each input works.
 * SEE https://docs.wpgraphql.com/extending/mutations/
 */
use GraphQL\Error\UserError;
function gql_register_email_mutation()
{
    // Define the input parameters
    $input_fields = [
        "to" => [
            "type" => ["list_of" => "String"],
            "description" =>
                "Array of email addresses to send email to. Must comply to RFC 2822 format.",
        ],
        "subject" => [
            "type" => "String",
            "description" => "Email subject.",
        ],
        "message" => [
            "type" => "String",
            "description" => "Message contents.",
        ],
        "headers" => [
            "type" => ["list_of" => "String"],
            "description" =>
                "Array of any additional headers. This is how you set BCC, CC and HTML type. See wp_mail() function for more details.",
        ],
        "trap" => [
            "type" => "String",
            "description" =>
                "Crude anti-spam measure. This must equal the clientMutationId, otherwise the email will not be sent.",
        ],
    ];

    // Define the ouput parameters
    $output_fields = [
        "to" => [
            "type" => ["list_of" => "String"],
            "description" =>
                "Array of email addresses to send email to. Must comply to RFC 2822 format.",
        ],
        "subject" => [
            "type" => "String",
            "description" => "Email subject.",
        ],
        "message" => [
            "type" => "String",
            "description" => "Message contents.",
        ],
        "headers" => [
            "type" => ["list_of" => "String"],
            "description" =>
                "Array of any additional headers. This is how you set BCC, CC and HTML type. See wp_mail() function for more details.",
        ],
        "sent" => [
            "type" => "Boolean",
            "description" => "Returns true if the email was sent successfully.",
            "resolve" => function ($payload, $args, $context, $info) {
                return isset($payload["sent"]) ? $payload["sent"] : false;
            },
        ],
    ];

    // This function processes the submitted data
    $mutate_and_get_payload = function ($input, $context, $info) {
        // Spam honeypot. Make sure that the clientMutationId matches the trap input.
        if ($input["clientMutationId"] !== $input["trap"]) {
            throw new UserError("You got caught in a spam trap");
        }

        // Vailidate email before trying to send
        foreach ($input["to"] as $email) {
            if (!is_email($email)) {
                throw new UserError("Invalid email address: " . $email);
            }
        }

        // Send email!
        $input["sent"] = wp_mail(
            $input["to"],
            $input["subject"],
            $input["message"],
            $input["headers"],
            $input["attachments"]
        );

        return $input;
    };

    // Add mutation to WP-GQL now
    $args = [
        "inputFields" => $input_fields,
        "outputFields" => $output_fields,
        "mutateAndGetPayload" => $mutate_and_get_payload,
    ];
    register_graphql_mutation("sendEmail", $args);
}
//add_action( 'graphql_register_types', 'gql_register_email_mutation');

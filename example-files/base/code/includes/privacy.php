<?hh //strict

/* FILE FOR DSGVO STUFF */

namespace codeneric\phmm\base\includes;

class Privacy {

  public static function data_remover(): void {}

  public static function get_privacy_content(): void {
    // TODO: write text
    if (!function_exists('wp_add_privacy_policy_content')) {
      return;
    }

    $content = "";
    $content .= "<h3>What Photography Management collects</h3>";
    $content .= "The plugin Photography Management does not collect personal data from visitors, it only collects personal data from logged in users. The personal data is limited to data gathered by the user's interaction with the plugin, which are the following: 'proofing' (liking) images and commenting images.";
    wp_add_privacy_policy_content(
      'Photography Management',
      wp_kses_post(wpautop($content, false)),
    );

  } 

  public static function export_item(
    string $email_address,
    int $page = 1,
  ): shape(
    'done' => bool,
    'data' => array<\codeneric\phmm\type\privacy\item>,
  ) {

    $emptyReturn = shape('done' => true, 'data' => []);

    /* Is the email_address a client ? */

    $user = get_user_by('email', $email_address);

    if ($user instanceof \WP_User) {
      $clientID = Client::get_client_id_from_wp_user_id($user->ID);

      if (is_null($clientID)) {
        return $emptyReturn;
      }

      $config = \codeneric\phmm\Configuration::get();
      $data = [];

      $projects = Client::get_project_ids($clientID);

      $projectTitles = array_map(
        function($projectID) {
          return Project::get_title_with_id_default($projectID);
        },
        $projects,
      );

      $data[] = shape(
        'name' => 'Assigned Projects',
        'value' =>
          count($projectTitles) > 0 ? implode(",", $projectTitles) : "none",
      );

      $item = shape(
        'group_id' => $config['plugin_slug_abbr'],
        'group_label' => 'Photography Management',
        'item_id' => $config['client_post_type'].'-'.$clientID,
        'data' => $data,
      );

      return shape('done' => true, 'data' => [$item]);

    } else {

      /* No user with this email*/

      return $emptyReturn;
    }

  }

  public static function register_data_exporter(
    array<string, \codeneric\phmm\type\privacy\exporter> $exporters,
  ): array<string, \codeneric\phmm\type\privacy\exporter> {

    $exporters[\codeneric\phmm\Configuration::get()['plugin_name']] = shape(
      'exporter_friendly_name' => __('Photography Management Plugin'),
      'callback' => [Privacy::class, 'export_item'],
    );
    return $exporters;

  }

}

<?hh //strict

namespace codeneric\phmm\base\includes;

require_once plugin_dir_path(__FILE__).'image.php';

use codeneric\phmm\Utils;
use codeneric\phmm\Configuration;
use codeneric\phmm\base\admin\Settings;

// enum Properties: string {
//   title =  'title';
//   description = 'description';
//   gallery = 'gallery';
//   is_private = 'is_private';
//   permalink = 'permalink';
//   id = 'id';
//   thumbnail = 'thumbnail';
//   pwd =  'pwd';
//   configuration = 'configuration';
// }

class Project {

  public static function is_assigned_to_at_least_one_client(
    int $projectID,
  ): bool {

    $clientIDs = Client::get_all_ids();

    foreach ($clientIDs as $id) {

      $projectIDs = Client::get_project_ids($id);
      if (in_array($projectID, $projectIDs))
        return true;

    }

    return false;

  }
  // public static function normalize_gallery(mixed $project) {

  //   if (is_array($project) && array_key_exists('gallery', $project)) {

  //   }

  //   if (isset($raw['gallery'])) {
  //     if (is_string($raw['gallery'])) {
  //       $project['gallery'] = explode(',', $raw['gallery']);
  //     } else if (is_array($raw['gallery'])) {
  //       $project['gallery'] = $raw['gallery'];
  //     } else {
  //       throw new Exception(
  //         "Unidentified 'gallery' property in raw project-data",
  //       );
  //     }
  //   } else {
  //     $project['gallery'] = array();
  //   }
  //   foreach ($project['gallery'] as &$id) {
  //     $id = intval($id);
  //   }
  // }

  public static function save_project(
    int $id,
    \codeneric\phmm\type\project_from_admin $project,
  ): void {

    update_post_meta($id, 'version', Configuration::get()['version']);

    update_post_meta($id, 'gallery', $project['gallery']);
    update_post_meta($id, 'protection', $project['protection']);

    update_post_meta(
      $id,
      "post_password",
      $project['protection']['password'],
    );

    if (!is_null($project['thumbnail'])) // optional field
      update_post_meta($id, 'thumbnail', $project['thumbnail']);

    // the $POST has no thumbnail and we have one in storage, that means we have to delete our stored reference
    if (is_null($project['thumbnail']) &&
        !is_null(self::get_meta_thumbnail($id)))
      delete_post_meta($id, 'thumbnail');

    // update_post_meta($id, 'is_private', $project['is_private']);

    update_post_meta($id, 'configuration', $project['configuration']);
  }
  /**
   * Returns all project ids
   * @return Array<int> containing all project ids
   */
  public static function get_all_ids(): array<int> {
    $projectIDs = get_posts(
      array(
        'post_type' => Configuration::get()['project_post_type'],
        'post_status' => 'any',
        'numberposts' => -1, // 'order'    => 'ASC'
        'fields' => 'ids',
      ),
    );

    invariant(
      is_array($projectIDs),
      '%s',
      new Error('Expected array getting project IDs'),
    );

    return $projectIDs;
  }

  public static function get_content(int $id): string {
    $post = get_post($id);
    invariant(
      !is_null($post),
      '%s',
      new Error('get_post did not return a post'),
    );

    $content = $post->post_content;
    $content = apply_filters('the_content', $content);
    return str_replace(']]>', ']]&gt;', $content);

  }

  private static function get_gallery_for_dashboard(
    int $id,
  ): array<\codeneric\phmm\type\image> {
    // $galleryString = (string) get_post_meta($id, 'gallery', true);

    // if ($galleryString === "")
    //   return array();

    // $IDs = explode(",", $galleryString);
    $IDs = self::get_gallery_image_ids($id);

    $map = function($i) {
      return \codeneric\phmm\base\includes\Image::get_image($i, false);
    };
    $imgs = array_map($map, $IDs);
    $res = [];
    foreach ($imgs as $i) {
      if (!is_null($i)) {
        $res[] = $i;
      }
    }
    return $res;

    // if (count($IDs) > 0) {
    //   return array_map($map, $IDs);
    // } else
    //   return array();
  }

  private static function get_gallery_for_frontend(
    int $id,
    int $preloadCount = 10,
  ): \codeneric\phmm\type\to_js\project_gallery {
    // $galleryString = (string) get_post_meta($id, 'gallery', true);

    // if ($galleryString === "")
    //   return null;

    // $IDs = explode(",", $galleryString);
    $IDs = self::get_gallery_image_ids($id);

    $order = $IDs;

    $preloaded = [];
    $query_args = ['project_id' => "$id"];
    foreach ($IDs as $index => $ID) {
      if ($index < $preloadCount) {
        $image = \codeneric\phmm\base\includes\Image::get_image(
          $ID,
          true,
          $query_args,
        );
        if (!is_null($image)) {
          $preloaded[] = $image;
          // array_shift($pendingIDs);
        }

      }
    }

    return shape('order' => $order, 'preloaded' => $preloaded);

  }

  public static function get_thumbnail(
    int $id,
    bool $withMinithumb = true,
  ): ?\codeneric\phmm\type\image {
    invariant(
      get_post_type($id) === Configuration::get()['project_post_type'],
      '%s',
      new Error("Given ID must be of post project post type"),
    );
    $raw =
      Utils::get_post_meta_ONLY_USE_IN_HELPER_FUNCTIONS($id, 'thumbnail');
    // $raw = get_post_meta($id, 'thumbnail', true);

    $query_args = ['project_id' => "$id"];

    if (!is_null($raw)) {
      // one exists
      $thumbnailID = (int) $raw;

      $image = Image::get_image($thumbnailID, $withMinithumb, $query_args);

      // valid image?
      if (!is_null($image) && $image['error'] !== true)
        return $image;
    }

    // none exists or failed fetching for some reason

    // -> load first gallery image

    $IDs = self::get_gallery_image_ids($id);

    if (count($IDs) > 0) {
      $firstImage = $IDs[0];
      $image =
        Image::get_image((int) $firstImage, $withMinithumb, $query_args);

      // valid image?
      if (!is_null($image) && $image['error'] !== true)
        return $image;
    }

    // -> load default

    $defaultIDString = get_option(
      Configuration::get()['default_thumbnail_id_option_key'],
      null,
    );

    if (!is_string($defaultIDString))
      return null; // tears in heaven

    $defaultID = (int) $defaultIDString;

    return Image::get_image($defaultID, $withMinithumb, $query_args);

  }

  public static function get_configuration(
    int $id,
  ): \codeneric\phmm\type\configuration {

    invariant(
      get_post_type($id) === Configuration::get()['project_post_type'],
      '%s',
      new Error("Given ID must be of post project post type"),
    );

    $raw =
      Utils::get_post_meta_ONLY_USE_IN_HELPER_FUNCTIONS($id, 'configuration');

    if (is_array($raw)) {
      $configuration =
        array_merge(self::getDefaultProjectConfiguration(), $raw);
    } else
      $configuration = self::getDefaultProjectConfiguration();

    $data = \codeneric\phmm\validate\configuration($configuration);

    return $data;
  }

  public static function get_title(int $id): ?string {
    // do not use get_the_title because it will append 'Protected' in some cases: https://developer.wordpress.org/reference/functions/get_the_title/
    $post = get_post($id);
    if (is_null($post))
      return null;
    return (string) $post->post_title;
  }
  public static function get_title_with_id_default(int $id): string {
    $maybe = self::get_title($id);

    return is_null($maybe) ? "#".$id : $maybe;
  }

  private static function get_meta_thumbnail(int $id): ?int {
    $raw =
      Utils::get_post_meta_ONLY_USE_IN_HELPER_FUNCTIONS($id, 'thumbnail');

    if (!is_null($raw))
      return (int) $raw;

    return null;
  }

  public static function get_gallery_image_ids(int $id): array<int> {
    $gallery =
      Utils::get_post_meta_ONLY_USE_IN_HELPER_FUNCTIONS($id, 'gallery');
    if (!is_array($gallery))
      $gallery = [];

    return array_map(
      function($ID) {
        if (is_string($ID))
          return (int) $ID; else
          return $ID; // TODO: check whether this case can happen
      },
      $gallery,
    );

  }

  public static function get_default_protection(
  ): \codeneric\phmm\type\project_protection {
    return shape(
      "password_protection" => false,
      "private" => false,
      "password" => null,
    );
  }
  public static function get_protection(
    int $id,
  ): \codeneric\phmm\type\project_protection {
    $raw =
      Utils::get_post_meta_ONLY_USE_IN_HELPER_FUNCTIONS($id, 'protection');

    if (!is_array($raw))
      return self::get_default_protection();

    try {
      return \codeneric\phmm\validate\project_protection($raw);
    } catch (\Exception $e) {
      return self::get_default_protection();
    }

  }

  public static function get_project_gallery(
    int $id,
    bool $minithumbs = false,
  ): array<\codeneric\phmm\type\image> {
    $IDs = self::get_gallery_image_ids($id);

    $order = $IDs;
    $res = [];

    foreach ($IDs as $index => $ID) {
      $image =
        \codeneric\phmm\base\includes\Image::get_image($ID, $minithumbs);
      if (!is_null($image)) {
        $res[] = $image;
      }
    }

    return $res;

  }

  public static function get_project_for_admin(
    int $id,
  ): \codeneric\phmm\type\project_to_admin {

    $gallery = self::get_gallery_image_ids($id);
    $thumbID = self::get_meta_thumbnail($id);
    $project = shape(
      // 'title' => (string) get_the_title($id),
      // 'description' => $this->get_content($id),
      'gallery' => is_null($gallery) ? [] : $gallery,
      // 'is_private' => (bool) get_post_meta($id, 'is_private', true), //TODO: get_post_meta returns false on failure, no way for us to handle this error since valid type,,
      // 'permalink' => (string) get_permalink($id),
      'id' => $id,
      'thumbnail' =>
        is_null($thumbID) ? null : Image::get_image($thumbID, true),
      'pwd' => null,
      'configuration' => self::get_configuration($id),
      'protection' => self::get_protection($id),
    );

    // $data = \codeneric\phmm\validate\project_to_admin($project);

    return $project;
  }

  public static function get_project_for_frontend(
    int $id,
    ?int $clientID = null,
  ): \codeneric\phmm\type\project_to_client {
    $gallery = self::get_gallery_for_frontend($id, 10);

    $clientConfig = null;

    if (!is_null($clientID))
      $clientConfig = Client::get_project_configuration($clientID, $id);

    $download_base_url = "";

    $wp_upload_dir = wp_upload_dir();

    if (array_key_exists("baseurl", $wp_upload_dir))
      $download_base_url =
        $wp_upload_dir['baseurl']."/photography_management/";

    $download_base_url = Utils::get_protocol_relative_url($download_base_url);

    $project = shape(
      // 'labels' =>
      //   is_null($clientID)
      //     ? []
      //     : [
      //       shape(
      //         //TODO: This is hardcoded for Favorites for now
      //         "id" => (string) InternalLabelID::Favorites,
      //         "images" => Labels::get_set(
      //           $clientID,
      //           $id,
      //           (string) InternalLabelID::Favorites,
      //         ),
      //       ),
      //     ],
      'labels' => [
        shape(
          //TODO: This is hardcoded for Favorites for now
          "id" => (string) InternalLabelID::Favorites,
          "images" => Labels::get_set(
            is_null($clientID) ? 0 : $clientID,
            $id,
            (string) InternalLabelID::Favorites,
          ),
        ),
      ],
      'comment_counts' => Utils::apply_filter_or(
        "codeneric/phmm/get_comment_counts",
        ['project_id' => $id, 'client_id' => $clientID],
        [],
      ),
      'gallery' =>
        is_null($gallery)
          ? shape("order" => [], "preloaded" => [])
          : $gallery,
      // 'is_private' => (bool) get_post_meta($id, 'is_private', true), //TODO: get_post_meta returns false on failure, no way for us to handle this error since valid type,,
      'id' => $id,
      'configuration' =>
        is_null($clientConfig)
          ? self::get_configuration($id)
          : $clientConfig,
      'download_base_url' => $download_base_url,
      'thumbnail' => null,
    );

    return $project;
  }

  public static function getDefaultProjectConfiguration(
  ): \codeneric\phmm\type\configuration {
    return \codeneric\phmm\validate\configuration([]);
  }

  public static function get_favorites(
    int $project_id,
    int $client_id,
  ): array<int> {
    return Labels::get_set(
      $client_id,
      $project_id,
      (string) InternalLabelID::Favorites,
    );
  }

  public static function get_number_of_zip_parts(
    int $project_id,
    string $mode,
    ?int $client_id,
  ): int {
    $batches =
      self::partition_gallery_into_batches($project_id, $mode, $client_id);
    return count($batches);
  }

  public static function partition_gallery_into_batches(
    int $project_id,
    string $mode,
    ?int $client_id,
  ): array<array<string>> {
    $gallery = self::get_gallery_image_ids($project_id);
    $files = array();
    if ($mode === 'zip-favs') {
      invariant(
        !is_null($client_id),
        '%s',
        new Error('client_id has to be specified for favs mode!'),
      );
      $gallery = self::get_favorites($project_id, $client_id);
    }

    foreach ($gallery as $attach_id) {
      $files[] = get_attached_file($attach_id);
    }

    return self::partition_files_into_batches($files);
  }

  private static function partition_files_into_batches(
    array<string> $file_paths,
  ): array<array<string>> {
    $settings = Settings::getCurrentSettings();
    $max_zip_part_size = $settings['max_zip_part_size'];
    $curr_part_size = 0; //size in byte
    $max_part_size = $max_zip_part_size * 1000000; //size in byte

    $patches = array(array());
    $patch_index = 0;
    foreach ($file_paths as $i => $path) {
      $file_size = filesize($path);
      if ($curr_part_size + $file_size < $max_part_size) {
        $curr_part_size += $file_size;
      } else {
        $patch_index++;
        $patches[$patch_index] = array();
        $curr_part_size = $file_size;
      }
      $patches[$patch_index][] = $path;
    }

    return $patches;
  }

  public static function get_zip_batch(
    int $project_id,
    string $mode,
    int $batch,
    ?int $client_id,
  ): array<string> {
    $batches =
      self::partition_gallery_into_batches($project_id, $mode, $client_id);
    invariant(
      count($batches) > $batch,
      '%s',
      new Error('batch is out of bound!'),
    );
    return $batches[$batch];
  }
}

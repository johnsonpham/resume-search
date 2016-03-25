<?php
//var_dump(strtotime('2015-02-19'));die;

require __DIR__ . '/../vendor/autoload.php';

$servername = "";
$username = "";
$password = "";
$dbname = "";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);
// Check connection
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

function extractLocation($conn, &$item)
{
  $resumeid = $item["resumeid"];
  $sql = "Select cityname, languageid 
        From tblresume_location inner join tblref_city on tblresume_location.cityid = tblref_city.cityid 
        Where resumeid = $resumeid";
  $result = $conn->query($sql);
  while ($location = $result->fetch_assoc()) {
    if ($location['languageid'] == 1) {
      $item['location_vi'] = $location['cityname'];
    }
    else {
      $item['location_en'] = $location['cityname'];
    }
  }
  unset($item['location']);
}

function extractIndustry($conn, &$item)
{
  $categories = explode(',', $item['category']);
  foreach ($categories as $category) {
    if (!empty($category)) {
      $sql = "Select languageid, industryname From tblref_industry Where industryid = $category";
      $result = $conn->query($sql);
      while ($industry = $result->fetch_assoc()) {
        if ($industry['languageid'] == 1) {
          $item['category_vi'] = $industry['industryname'];
        }
        else {
          $item['category_en'] = $industry['industryname'];
        }
      }
    }
  }
  unset($item['category']);
}

function extractJobLevel($conn, &$item, $jobLevelId, $fieldName)
{
  $sql = "Select joblevelname, languageid From tblref_joblevel Where joblevelid = $jobLevelId";
  $result = $conn->query($sql);
  $fieldNameVi = $fieldName . "_vi";
  $fieldNameEn = $fieldName . "_en";
  while ($jobLevel = $result->fetch_assoc()) {
    if ($jobLevel['languageid'] == 1) {
      $item[$fieldNameVi] = $jobLevel['joblevelname'];
    }
    else {
      $item[$fieldNameEn] = $jobLevel['joblevelname'];
    }
  }
}

function extractAttached($conn, &$item) {
  $resumeid = $item["resumeid"];
  $sql = "SELECT isAttached FROM tblresume WHERE resumeid = $resumeid";
  $result = $conn->query($sql);
  while ($row = $result->fetch_assoc()) {
    $item["attached"] = $row["isAttached"] == 1 ? true : false;
  }
}

function extractTotal($conn, &$item) {
  $resumeid = $item["resumeid"];
  $sql = "SELECT SUM(views) totalViews, SUM(downloads) totalDownloads
          FROM (
            SELECT resumeid resumeId, 0 AS views, 1 AS downloads FROM tblresume_download_tracking WHERE resumeid = $resumeid 
            UNION ALL
            SELECT resume_id resumeId, 0 AS views, 1 AS downloads FROM track_resume_download WHERE resume_id = $resumeid
            UNION ALL
            SELECT resume_id resumeId, noofviewed AS views,0 AS downloads FROM track_resume_view WHERE resume_id = $resumeid
          ) f
          GROUP BY resumeId";
  $result = $conn->query($sql);
  $item["total_views"] = 0;
  $item["total_downloads"] = 0;
  while ($row = $result->fetch_assoc()) {
    $item["total_views"] = (int) + $row["totalViews"];
    $item["total_downloads"] = (int) + $row["totalDownloads"];
  }
}

function extractCompletionRate($conn, &$item) {
  $resumeid = $item["resumeid"];
  $sql = "SELECT completionRate FROM tblresume_extra_info WHERE resumeId = $resumeid";
  $result = $conn->query($sql);
  $item["completion_rate"] = 0;
  while ($row = $result->fetch_assoc()) {
    $item["completion_rate"] = (int) + $row["completionRate"];
  }
}

function extractYearExperienceResume($conn, &$item) {
  $yearid = $item["yearsexperienceid"];
  $sql = "Select languageid, yearsexperiencename From tblref_yearsexperience_resume Where yearsexperienceid = $yearid";
  $result = $conn->query($sql);
  while ($row = $result->fetch_assoc()) {
    if ($row['languageid'] == 1) {
      $item["exp_years_vi"] = $row['yearsexperiencename'];
    }
    else {
      $item["exp_years_en"] = $row['yearsexperiencename'];
    }
  }
  unset($item['yearsexperienceid']);
}

$page = 1;
$count = 1000;
$totalPage = 4;
while (true) {
  $offset = ($page - 1) * $count;
  $sql = "Select resumeid, fullname, category, content, desiredjobtitle as desired_job_title, desiredjoblevelid, 
    education, skill, resumetitle as resume_title, exp_description, 
    edu_major, lastdateupdated as updated_date, joblevel, mostrecentemployer as most_recent_employer, 
    suggestedsalary as suggested_salary, exp_jobtitle, mostrecentposition as most_recent_position, 
    yearsexperienceid
    From tblresume_search_all limit $offset, $count";
  $result = $conn->query($sql);

  if ($result->num_rows > 0) {
    $data = [];

    while ($row = $result->fetch_assoc()) {
      $item = $row;
      $item["suggested_salary"] = (int) + $item["suggested_salary"];
      $item["updated_date"] = strtotime($item["updated_date"]);
//      date("Y-d-m", strtotime('2015-02-19'))

      extractLocation($conn, $item);

      extractIndustry($conn, $item);

      extractJobLevel($conn, $item, $item["joblevel"], "job_level");
      unset($item['joblevel']);

      extractJobLevel($conn, $item, $item["desiredjoblevelid"], "desired_job_level");
      unset($item['desiredjoblevelid']);

      extractAttached($conn, $item);

      extractTotal($conn, $item);

      extractCompletionRate($conn, $item);

      extractYearExperienceResume($conn, $item);

      $data[] = $item;
    }

    $client = new \AlgoliaSearch\Client("G9K82IDUDX", "876286a34d35bf9c8b4a8d1398c22a6a");
    $index = $client->initIndex('resumes');

    $batch = array();
    foreach ($data as $row) {
      $row['objectID'] = $row['resumeid'];
      array_push($batch, $row);
      if (count($batch) == $count) {
        $index->saveObjects($batch);
        $batch = array();
      }
    }

    echo ($page * $count) . " records has been saved" . PHP_EOL;
  }
  else {
    var_dump($result);
    echo "0 results";
    break;
  }

//  var_dump($data);die;

  $page++;
  if ($page > $totalPage) {
    break;
  }
}

$conn->close();
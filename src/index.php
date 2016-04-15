<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../conf/config.php';

echo "START " . date("Y/m/d H:i:s") . "\n";
$conn = new mysqli(SERVER_NAME, USERNAME, PASSWORD, DB_NAME);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

$page = 1;
$totalFailedRecords = 0;
$client = new \AlgoliaSearch\Client(ALGOLIA_APP_ID, ALGOLIA_APP_KEY);
$index = $client->initIndex(ALGOLIA_INDEX);

if (defined('STDIN') && isset($argc) && $argc > 1) {
  $secondsAgo = intval($argv[1]);
}
else {
  $secondsAgo = 300;
}

$buffer_time = 30;

$time_end = date("Y-m-d H:i:00", time() + $buffer_time);
$time_start = date("Y-m-d H:i:00", time() - ($secondsAgo + $buffer_time));

function printSQL($sql)
{
  if (PRINT_SQL) {
    echo "\n$sql;\n";
  }
}

function extractLocation($conn, &$item)
{
  $resumeid = $item["resumeid"];
  if ($resumeid == null) return;
  $sql = "Select cityname, languageid 
        From tblresume_location inner join tblref_city on tblresume_location.cityid = tblref_city.cityid 
        Where resumeid = $resumeid";
  $result = $conn->query($sql);
  $item['location_vi'] = array();
  $item['location_en'] = array();
  while ($location = $result->fetch_assoc()) {
    if ($location['languageid'] == 1) {
      array_push($item['location_vi'], $location['cityname']);
    }
    else {
      array_push($item['location_en'], $location['cityname']);
    }
  }
  unset($item['location']);
}

function extractIndustry($conn, &$item)
{
  $categories = explode(',', $item['category']);
  $item['category_vi'] = array();
  $item['category_en'] = array();
//  array_push($batch, $row);
  foreach ($categories as $category) {
    if (!empty($category)) {
      $sql = "Select languageid, industryname From tblref_industry Where industryid = $category";
      $result = $conn->query($sql);
      while ($industry = $result->fetch_assoc()) {
        if ($industry['languageid'] == 1) {
          array_push($item['category_vi'], $industry['industryname']);
//          $item['category_vi'] = $industry['industryname'] + ", ";
        }
        else {
//          $item['category_en'] = $industry['industryname'];
          array_push($item['category_en'], $industry['industryname']);
        }
      }
    }
  }
  unset($item['category']);
}

function extractJobLevel($conn, &$item, $jobLevelId, $fieldName)
{
  if ($jobLevelId == null) return;
  $sql = "Select joblevelname, languageid From tblref_joblevel Where joblevelid = $jobLevelId";
  printSQL($sql);

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
  //$jobLevelsOrder = array("GRADUATE", "GRADUATE", "EXPERIENCED", "MANAGER");
  $item["credit_job_level"] = ($jobLevelId <= 1) ? "GRADUATE" :
    (in_array($jobLevelId, array(5, 6)) ? "EXPERIENCED" : "MANAGER");
  $item["credit_job_level"] = strtoupper("JOBLEVEL_" . $item["credit_job_level"]);
}

function extractAttached($conn, &$item)
{
  $resumeid = $item["resumeid"];
  if ($resumeid == null) return;
  $sql = "SELECT isAttached FROM tblresume WHERE resumeid = $resumeid";
  printSQL($sql);
  $result = $conn->query($sql);
  while ($row = $result->fetch_assoc()) {
    $item["attached"] = $row["isAttached"] == 1 ? true : false;
  }
}

function extractLanguageName($conn, &$langProficiency)
{
  $langId = $langProficiency->lang;
  if ($langId == null) return;
  $sql = "Select languageproficiencyname from tblref_languageproficiency where languageproficiencyid = $langId";
  $result = $conn->query($sql);
  if ($row = $result->fetch_assoc()) {
    $langProficiency->lang = $row["languageproficiencyname"];
  }
}


function extractLanguageLevel($conn, &$langProficiency)
{
  $levelId = $langProficiency->level;
  if ($levelId == null) return;
  $sql = "Select languagelevelname, languageid from tblref_languagelevel where languagelevelid = $levelId";
  $result = $conn->query($sql);
  while ($row = $result->fetch_assoc()) {
    if ($row['languageid'] == 1) {
      $langProficiency->level_vi = $row["languagelevelname"];
    }
    else {
      $langProficiency->level_en = $row["languagelevelname"];
    }
  }
  unset($langProficiency->level);
}

function extractLangLevel($conn, &$item, $lang, $langLevel)
{
  if (isset($lang) && $lang > 0) {
    if (!isset($item["lang_proficiency"])) {
      $item["lang_proficiency"] = array();
    }

    $langProficiency = new stdClass();
    $langProficiency->lang = $lang;
    $langProficiency->level = $langLevel;
    extractLanguageLevel($conn, $langProficiency);
    extractLanguageName($conn, $langProficiency);

//    if (!isset($item["_tags"])) {
//      $item["_tags"] = array();
//    }

    //extract credit
    $sql = "select * from tblsys_parameter where parcode = 'RS_MULTICREDIT_" .
      strtoupper($langProficiency->lang . "_" . $langProficiency->level_en) . "'";
    $result = $conn->query($sql);
    if ($row = $result->fetch_assoc()) {
      $item["credits"] = (int)max($row["parvalue"], $item["credits"]);
    }

//    $item["credit_language"] = strtoupper($item["language1_name"] . "_" . $item["language1_proficiency_en"]);
//    array_push($item["_tags"],
//      $langProficiency->lang . "-" .$langProficiency->level_vi,
//      $langProficiency->lang . "-" . $langProficiency->level_en);
    array_push($item["lang_proficiency"], $langProficiency);
  }
}

function extractLanguageProficiency($conn, &$item)
{
  $resumeid = $item["resumeid"];
  if ($resumeid == null) return;

  $sql = "SELECT isAttached, language1,
      language1, language2, language3, language4,
      languagelevel1, languagelevel2, languagelevel3, languagelevel4
			FROM tblresume 
			WHERE resumeid = $resumeid";

  $result = $conn->query($sql);
  if ($result->num_rows == 0) {
    return;
  }

  if ($row = $result->fetch_assoc()) {
    $item["attached"] = $row["isAttached"] == 1 ? true : false;

    $item["credits"] = 0;
    extractLangLevel($conn, $item, $row["language1"], $row["languagelevel1"]);
    extractLangLevel($conn, $item, $row["language2"], $row["languagelevel2"]);
    extractLangLevel($conn, $item, $row["language3"], $row["languagelevel3"]);
//    extractLangLevel($conn, $item, $row["language4"], $row["languagelevel4"]);

//    $item["Lang_proficiency"] = array();

//    if (isset($row["language1"]) && $row["language1"] > 0) {
//      $langProficiency = new stdClass();
//      $langProficiency->lang = $row["language1"];
//      $langProficiency->level = $row["languagelevel1"];
//      array_push($item["Lang_proficiency"], $langProficiency);
//    }
//
//    $langProficiency = new stdClass();
//    $langProficiency->lang = $row["language2"];
//    $langProficiency->level = $row["languagelevel2"];
//    array_push($item["Lang_proficiency"], $langProficiency);
//
//    $langProficiency = new stdClass();
//    $langProficiency->lang = $row["language3"];
//    $langProficiency->level = $row["languagelevel3"];
//    array_push($item["Lang_proficiency"], $langProficiency);
//
//    $langProficiency = new stdClass();
//    $langProficiency->lang = $row["language4"];
//    $langProficiency->level = $row["languagelevel4"];
//    array_push($item["Lang_proficiency"], $langProficiency);

//    $item["language1"] = $row["language1"];
//    $item["language2"] = $row["language2"];
//    $item["language3"] = $row["language3"];
//    $item["language4"] = $row["language4"];
//    $item["languagelevel1"] = $row["languagelevel1"];
//    $item["languagelevel2"] = $row["languagelevel2"];
//    $item["languagelevel3"] = $row["languagelevel3"];
//    $item["languagelevel4"] = $row["languagelevel4"];

    // Language proficiency: flat now and only the 1st(will nested and multi later)
//    extractLanguage($conn, $item);
//    extractLanguageProficiency($conn, $item);

//    if (isset($item["language1_name"]) && isset($item["language1_proficiency_en"])) {
//      $item["credit_language"] = strtoupper($item["language1_name"] . "_" . $item["language1_proficiency_en"]);
//    }
//    else {
//      $item["credit_language"] = "";
//    }
  }

}

function extractTotal($conn, &$item)
{
  $resumeid = $item["resumeid"];
  if ($resumeid == null) return;

//  $sql = "SELECT SUM(views) totalViews, SUM(downloads) totalDownloads
//          FROM (
//            SELECT resumeid resumeId, 0 AS views, 1 AS downloads FROM tblresume_download_tracking WHERE resumeid = $resumeid
//            UNION ALL
//            SELECT resume_id resumeId, 0 AS views, 1 AS downloads FROM track_resume_download WHERE resume_id = $resumeid
//            UNION ALL
//            SELECT resume_id resumeId, noofviewed AS views,0 AS downloads FROM track_resume_view WHERE resume_id = $resumeid
//          ) f
//          GROUP BY resumeId";
  $item["total_downloads"] = 0;
  $sql = "SELECT count(*) as totalDownloads FROM tblresume_download_tracking t WHERE resumeid = $resumeid";
  $result = $conn->query($sql);
  if ($row = $result->fetch_assoc()) {
    $item["total_downloads"] = (int)+$row["totalDownloads"];
  }
  $sql = "SELECT count(*) as totalDownloads FROM track_resume_download t WHERE resume_id = $resumeid";
  $result = $conn->query($sql);
  if ($row = $result->fetch_assoc()) {
    $item["total_downloads"] = $item["total_downloads"] + (int)+$row["totalDownloads"];
  }

  $item["total_views"] = 0;
  $sql = "SELECT count(*) as totalViews FROM track_resume_view t WHERE resume_id = $resumeid";
  $result = $conn->query($sql);
  if ($row = $result->fetch_assoc()) {
    $item["total_views"] = (int)+$row["totalViews"];
  }

//  while ($row = $result->fetch_assoc()) {
//    $item["total_views"] = (int)+$row["totalViews"];
//    $item["total_downloads"] = (int)+$row["totalDownloads"];
//  }
}

function extractCompletionRate($conn, &$item)
{
  $resumeid = $item["resumeid"];
  if ($resumeid == null) return;

  $sql = "SELECT completionRate FROM tblresume_extra_info WHERE resumeId = $resumeid";
  printSQL($sql);
  $result = $conn->query($sql);
  $item["completion_rate"] = 0;
  while ($row = $result->fetch_assoc()) {
    $item["completion_rate"] = (int)+$row["completionRate"];
  }
}

function extractYearExperienceResume($conn, &$item)
{
  $yearid = $item["yearsexperienceid"];
  if ($yearid == null) return;

  $sql = "Select languageid, yearsexperiencename From tblref_yearsexperience_resume Where yearsexperienceid = $yearid";
  printSQL($sql);
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


function extractNationality($conn, &$item)
{
  try {
    $nationalityid = $item["nationalityid"];
    if ($nationalityid == null) return;
    $sql = "select * from tblref_nationality where nationalityid = $nationalityid";
    printSQL($sql);

    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
      if ($row['languageid'] == 1) {
        $item["nationality_vi"] = $row['nationalityname'];
      }
      else {
        $item["nationality_en"] = $row['nationalityname'];
      }
    }
    unset($item['nationalityid']);
  } catch (Exception $e) {
//    $totalFailedRecords += ITEMS_PER_BATCH;
    echo 'Caught exception: ', $e->getMessage(), "\n";
  }
}

while (true) {
  echo "Page $page start " . date("Y/m/d H:i:s") . "\n";

  $offset = ($page - 1) * ITEMS_PER_BATCH;
  $sql = "Select resumeid, fullname, category, desiredjobtitle as desired_job_title, desiredjoblevelid,
    education, skill, resumetitle as resume_title, exp_description,
    edu_major, lastdateupdated as updated_date, joblevel, mostrecentemployer as most_recent_employer,
    suggestedsalary as suggested_salary, exp_jobtitle, mostrecentposition as most_recent_position,
    workexperience as work_experience, edu_description,
    yearsexperienceid, genderid, nationalityid, birthday
    From tblresume_search_all WHERE (lastdateupdated BETWEEN '$time_start' AND '$time_end') ORDER BY resumeid DESC limit $offset, " . ITEMS_PER_BATCH;
  $result = $conn->query($sql);

  if ($result->num_rows > 0) {
    $data = [];

    while ($row = $result->fetch_assoc()) {
      $item = $row;
      $item["suggested_salary"] = (int)+$item["suggested_salary"];
      $item["updated_date"] = strtotime($item["updated_date"]);
      $item["birthday"] = (int)+substr($item["birthday"], 0, 4);

      $item['gender'] = "female";
      if ($item['genderid'] == 1) {
        $item['gender'] = "male";
      }
      unset($item['genderid']);

      extractLocation($conn, $item);

      extractIndustry($conn, $item);

      extractJobLevel($conn, $item, $item["joblevel"], "job_level");

      extractJobLevel($conn, $item, $item["desiredjoblevelid"], "desired_job_level");

      extractAttached($conn, $item);

      extractTotal($conn, $item);

      extractCompletionRate($conn, $item);

      extractYearExperienceResume($conn, $item);

      extractNationality($conn, $item);

      extractLanguageProficiency($conn, $item);

//      extractCredits($conn, $item);

      $data[] = $item;
    }

    $batch = array();
    foreach ($data as $row) {
      $row['objectID'] = $row['resumeid'];
      array_push($batch, $row);
      if (count($batch) == ITEMS_PER_BATCH) {
        while (count($batch) > 0) {
          try {
            $index->saveObjects($batch);
            $batch = array();
          } catch (Exception $e) {
            $totalFailedRecords += 1;
            echo 'Caught exception: ', $e->getMessage(), "\n";
            echo 'try again without: ', $e["objectID"], "\n";
            $batch = array_filter($batch, function ($it) use ($e) {
              return $it["objectID"] == $e["objectID"];
            });
            continue;
          }
        }
      }
    }

    echo ($page * ITEMS_PER_BATCH - $totalFailedRecords) . " records have been saved" . PHP_EOL;
  }
  else {
    echo "0 results";
    break;
  }

  $page++;
  if ($page > TOTAL_BATCHES) {
    break;
  }
}

$conn->close();
echo "END " . date("Y/m/d H:i:s") . "\n";

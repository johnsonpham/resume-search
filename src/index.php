<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/config.php';


// Create connection
$conn = new mysqli(SERVER_NAME, USERNAME, PASSWORD, DB_NAME);
// Check connection
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

$page = 1;
$totalFailedRecords = 0;
$client = new \AlgoliaSearch\Client("G9K82IDUDX", "876286a34d35bf9c8b4a8d1398c22a6a");
$index = $client->initIndex('resumes');

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
  printSQL($sql);
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

function extractLanguage($conn, &$item)
{
  $langId = $item["language1"];
  if ($langId == null) return;
  $sql = "Select languageproficiencyname from tblref_languageproficiency where languageproficiencyid = $langId";
  printSQL($sql);
  $result = $conn->query($sql);

  $item["language1_name"] = "";
  while ($row = $result->fetch_assoc()) {
    $item["language1_name"] = $row["languageproficiencyname"];
  }
}

function extractLanguageProficiency($conn, &$item)
{
  $proficiencyId = $item["languagelevel1"];
  if ($proficiencyId == null) return;
  $sql = "Select languagelevelname, languageid from tblref_languagelevel where languagelevelid = $proficiencyId";
  printSQL($sql);
  $result = $conn->query($sql);
  $item["language1_proficiency_en"] = "";
  while ($row = $result->fetch_assoc()) {
    if ($row['languageid'] == 1) {
      $item["language1_proficiency_vi"] = $row["languagelevelname"];
    }
    else {
      $item["language1_proficiency_en"] = $row["languagelevelname"];
    }
  }
}

function extractFromMainResumeTbl($conn, &$item)
{
  $resumeid = $item["resumeid"];
  if ($resumeid == null) return;

  $sql = "SELECT isAttached, language1, languagelevel1 -- , language2, languagelevel2, language3, languagelevel3 
			FROM tblresume 
			WHERE resumeid = $resumeid";
  printSQL($sql);
  $result = $conn->query($sql);
  while ($row = $result->fetch_assoc()) {
    $item["attached"] = $row["isAttached"] == 1 ? true : false;
    $item["language1"] = $row["language1"];
    $item["languagelevel1"] = $row["languagelevel1"];

    // Language proficiency: flat now and only the 1st(will nested and multi later)
    extractLanguage($conn, $item);
    extractLanguageProficiency($conn, $item);

    if (isset($item["language1_name"]) && isset($item["language1_proficiency_en"])) {
      $item["credit_language"] = strtoupper($item["language1_name"] . "_" . $item["language1_proficiency_en"]);
    }
    else {
      $item["credit_language"] = "";
    }
  }
}

function extractTotal($conn, &$item)
{
  $resumeid = $item["resumeid"];
  if ($resumeid == null) return;

  $sql = "SELECT SUM(views) totalViews, SUM(downloads) totalDownloads
          FROM (
            SELECT resumeid resumeId, 0 AS views, 1 AS downloads FROM tblresume_download_tracking WHERE resumeid = $resumeid 
            UNION ALL
            SELECT resume_id resumeId, 0 AS views, 1 AS downloads FROM track_resume_download WHERE resume_id = $resumeid
            UNION ALL
            SELECT resume_id resumeId, noofviewed AS views,0 AS downloads FROM track_resume_view WHERE resume_id = $resumeid
          ) f
          GROUP BY resumeId";
  printSQL($sql);
  $result = $conn->query($sql);
  $item["total_views"] = 0;
  $item["total_downloads"] = 0;
  while ($row = $result->fetch_assoc()) {
    $item["total_views"] = (int)+$row["totalViews"];
    $item["total_downloads"] = (int)+$row["totalDownloads"];
  }
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

function extractCredits($conn, &$item)
{
  $sql = "select * from tblsys_parameter where parcode = 'RS_MULTICREDIT_" . $item["credit_job_level"] . "'";
  $result = $conn->query($sql);
  if ($row = $result->fetch_assoc()) {
    $item["credit"] = $row["parvalue"];
  }

  if (!isset($item["credit"])) {
    $item["credit"] = 0;
  }

  $sql = "select * from tblsys_parameter where parcode = 'RS_MULTICREDIT_" . $item["credit_language"] . "'";
  $result = $conn->query($sql);
  if ($row = $result->fetch_assoc()) {
    $item["credit"] = max($row["parvalue"], $item["credit"]);
  }

  unset($item["credit_job_level"]);
  unset($item["credit_language"]);
}

while (true) {
  $offset = ($page - 1) * ITEMS_PER_BATCH;
  $sql = "Select resumeid, fullname, category, content, desiredjobtitle as desired_job_title, desiredjoblevelid, 
    education, skill, resumetitle as resume_title, exp_description, 
    edu_major, lastdateupdated as updated_date, joblevel, mostrecentemployer as most_recent_employer, 
    suggestedsalary as suggested_salary, exp_jobtitle, mostrecentposition as most_recent_position, 
    yearsexperienceid, genderid, nationalityid, birthday
    From tblresume_search_all limit $offset, " . ITEMS_PER_BATCH;
  printSQL($sql);
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

      extractFromMainResumeTbl($conn, $item);

      extractCredits($conn, $item);

      $data[] = $item;
    }


    $batch = array();
    foreach ($data as $row) {
      $row['objectID'] = $row['resumeid'];
      array_push($batch, $row);
      while (true) {
        if (count($batch) == ITEMS_PER_BATCH) {
          try {
            $index->saveObjects($batch);
            $batch = array();
          }
          catch (Exception $e) {
            $totalFailedRecords += ITEMS_PER_BATCH;
            echo 'Caught exception: ', $e->getMessage(), "\n";
            echo 'try again without: ', $e["objectID"], "\n";
            $batch = array_filter($batch, function ($it) use ($e)  {
              return $it["objectID"] == $e["objectID"];
            });
            continue;
          }
        }
        break;
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

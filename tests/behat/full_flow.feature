@local @local_aicoursebuilder @javascript @_file_upload
Feature: From the documents to a course that exists
  In order to get a course written from my documents
  As a teacher
  I need to upload the documents, see the cost, follow the generation, correct the result, approve it and find the course

  Background:
    Given the following config values are set as admin:
      | defaultconnector | fake | local_aicoursebuilder |
      | joblimitusd      | 0    | local_aicoursebuilder |
      | userlimitusd     | 0    | local_aicoursebuilder |
      | sitelimitusd     | 0    | local_aicoursebuilder |
    And the following "categories" exist:
      | name   | category | idnumber |
      | Energy | 0        | ENERGY   |

  Scenario: Documents are uploaded, the cost is estimated, the course is generated, edited and approved, and the course exists
    Given the sources of the next job are numbered from 1
    And I log in as "admin"
    And I am on the "local_aicoursebuilder > wizard" page
    # The wizard: what to do, what the course is about, the documents, the estimate.
    When I set the field "What do you want to do?" to "Create a new course"
    And I set the field "Category of the new course" to "Energy"
    And I press "Next"
    And I set the field "What is the course about?" to "An introductory course about renewable energy"
    And I set the field "Audience" to "Operators"
    And I set the field "Duration (minutes)" to "120"
    And I press "Next"
    And I upload "local/aicoursebuilder/tests/fixtures/sources/behat_1_energie.txt" file to "Source files" filemanager
    And I upload "local/aicoursebuilder/tests/fixtures/sources/behat_2_aplicatii.txt" file to "Source files" filemanager
    And I press "Next"
    Then I should see "Estimated cost"
    And I should see "USD"
    # The generation: the first run ingests the sources and queues the generation, which the second run does.
    When I set the field "I accept the Moodle AI usage policy" to "1"
    And I press "Start generation"
    And I run all adhoc tasks
    And I run all adhoc tasks
    Then I wait until "[data-region='aicb-finished']" "css_element" exists
    And I should see "The blueprint is ready for review." in the "[data-region='aicb-progress']" "css_element"
    # The editor: a correction is saved as a new version, which is the one that is approved.
    When I click on "Review the blueprint" "link"
    Then I should see "Version 1"
    When I set the field "Title" to "Noțiuni esențiale de energie"
    And I press "Save"
    Then I should see "Version 2 · saved"
    When I press "Approve"
    And I click on "Approve" "button" in the "Approve blueprint" "dialogue"
    Then I should see "The blueprint was approved."
    # The build runs as a task, so the course exists only once it has run.
    When I run all adhoc tasks
    And I am on the "An introductory course about renewable energy" "local_aicoursebuilder > job" page
    Then I should see "The course is built" in the "[data-region='aicb-job-progress']" "css_element"
    When I click on "Open the course" "link"
    Then I should see "Noțiuni esențiale de energie"

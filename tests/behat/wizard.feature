@local @local_aicoursebuilder @javascript @_file_upload
Feature: Generate a course blueprint from the wizard
  In order to get a course written from my documents
  As a teacher
  I need to describe the course, see what it will cost and follow the generation

  Background:
    Given the following config values are set as admin:
      | defaultconnector | fake | local_aicoursebuilder |
      | joblimitusd      | 0    | local_aicoursebuilder |
      | userlimitusd     | 0    | local_aicoursebuilder |
      | sitelimitusd     | 0    | local_aicoursebuilder |
    And the following "categories" exist:
      | name   | category | idnumber |
      | Energy | 0        | ENERGY   |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Ana       | Popescu  | teacher1@example.com |
    And the following "courses" exist:
      | fullname      | shortname | category |
      | Energy basics | EB        | ENERGY   |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | EB     | editingteacher |

  Scenario: A new course is generated from two documents, from the first step to the finished blueprint
    Given I log in as "admin"
    And I am on the "local_aicoursebuilder > wizard" page
    Then I should see "Step 1 of 4"
    When I set the field "What do you want to do?" to "Create a new course"
    And I set the field "Category of the new course" to "Energy"
    And I press "Next"
    Then I should see "Step 2 of 4"
    When I set the field "What is the course about?" to "An introductory course about renewable energy"
    And I set the field "Audience" to "Operators"
    And I set the field "Duration (minutes)" to "120"
    And I press "Next"
    Then I should see "Step 3 of 4"
    When I upload "local/aicoursebuilder/tests/fixtures/sources/behat_1_energie.txt" file to "Source files" filemanager
    And I upload "local/aicoursebuilder/tests/fixtures/sources/behat_2_aplicatii.txt" file to "Source files" filemanager
    And I press "Next"
    Then I should see "Step 4 of 4"
    And I should see "Estimated cost"
    And I should see "USD"
    When I set the field "I accept the Moodle AI usage policy" to "1"
    And I press "Start generation"
    # The first run ingests the sources and queues the generation, which the second run does.
    And I run all adhoc tasks
    And I run all adhoc tasks
    Then I wait until "[data-region='aicb-finished']" "css_element" exists
    And I should see "The blueprint is ready for review." in the "[data-region='aicb-progress']" "css_element"
    And I should see "100%"

  Scenario: The wizard does not move on while a step is incomplete
    Given I log in as "admin"
    And I am on the "local_aicoursebuilder > wizard" page
    When I set the field "What do you want to do?" to "Create a new course"
    And I press "Next"
    And I press "Next"
    Then I should see "Describe the course."
    And I should see "Step 2 of 4"

  Scenario: A job is not started before the AI policy is accepted
    Given I log in as "admin"
    And I am on the "local_aicoursebuilder > wizard" page
    When I set the field "What do you want to do?" to "Create a new course"
    And I press "Next"
    And I set the field "What is the course about?" to "An introductory course about renewable energy"
    And I press "Next"
    And I press "Next"
    And I press "Start generation"
    Then I should see "Accept the Moodle AI usage policy to start."

  Scenario: A job that would cost more than the limit cannot be started
    Given the following config values are set as admin:
      | defaultconnector | deepseek | local_aicoursebuilder |
      | joblimitusd      | 0.0001   | local_aicoursebuilder |
    And I log in as "admin"
    And I am on the "local_aicoursebuilder > wizard" page
    When I set the field "What do you want to do?" to "Create a new course"
    And I press "Next"
    And I set the field "What is the course about?" to "An introductory course about renewable energy"
    And I press "Next"
    And I press "Next"
    Then I should see "The estimated cost is over your cost limits."
    And the "Start generation" "button" should be disabled

  Scenario: A teacher adds generated content to the course the wizard was opened from
    Given I log in as "teacher1"
    And I am on the "EB" "local_aicoursebuilder > wizard" page
    Then I should see "Step 1 of 4"
    And I should see "Add to an existing course"
    When I press "Next"
    And I set the field "What is the course about?" to "A short lesson about solar panels"
    And I press "Next"
    And I press "Next"
    Then I should see "Estimated cost"
    When I set the field "I accept the Moodle AI usage policy" to "1"
    And I press "Start generation"
    And I run all adhoc tasks
    Then I wait until "[data-region='aicb-finished']" "css_element" exists
    And I should see "The blueprint is ready for review." in the "[data-region='aicb-progress']" "css_element"

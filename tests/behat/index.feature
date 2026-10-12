@local @local_aicoursebuilder
Feature: Find my course generations and start a new one
  In order to get to what I have generated, and to generate more
  As a teacher
  I need a list of my jobs that I can reach from the navigation, with the way to a new course

  Background:
    Given the following config values are set as admin:
      | defaultconnector | fake | local_aicoursebuilder |
      | joblimitusd      | 0    | local_aicoursebuilder |
      | userlimitusd     | 0    | local_aicoursebuilder |
      | sitelimitusd     | 0    | local_aicoursebuilder |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Ana       | Popescu  | teacher1@example.com |
      | teacher2 | Dan       | Ionescu  | teacher2@example.com |
      | student1 | Eva       | Marin    | student1@example.com |
    And the following "courses" exist:
      | fullname        | shortname |
      | Energy basics   | EB        |
      | Energy advanced | EA        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | EB     | editingteacher |
      | teacher1 | EA     | editingteacher |
      | teacher2 | EB     | editingteacher |
      | student1 | EB     | student        |
    And "teacher1" has a "review" job "A course about solar energy" in "EB" cost 1.5
    And "teacher1" has a "paused" job "A course about wind turbines" in "EB" cost 0
    And "teacher2" has a "review" job "A course about databases" in "EB" cost 2.5

  Scenario: A teacher sees their own jobs, with their status and cost, and not those of others
    Given I log in as "teacher1"
    When I am on the "local_aicoursebuilder > index" page
    Then I should see "Course generations"
    And I should see "A course about solar energy" in the "[data-region='aicb-jobs-table']" "css_element"
    And I should see "Ready for review" in the "[data-region='aicb-jobs-table']" "css_element"
    And I should see "1.5000" in the "[data-region='aicb-jobs-table']" "css_element"
    And I should see "A course about wind turbines" in the "[data-region='aicb-jobs-table']" "css_element"
    And I should see "Paused by a cost limit" in the "[data-region='aicb-jobs-table']" "css_element"
    And I should not see "A course about databases"
    And I should see "Blueprint" in the "[data-region='aicb-jobs-table']" "css_element"
    And I should not see "Show the jobs of all users"

  Scenario: A teacher does not see the jobs of a course they have lost, while they can still use the plugin elsewhere
    Given "teacher1" has a "review" job "A course about hydrogen" in "EA" cost 7.5
    And I log in as "teacher1"
    And I am on the "local_aicoursebuilder > index" page
    Then I should see "A course about hydrogen" in the "[data-region='aicb-jobs-table']" "css_element"
    When "teacher1" is no longer enrolled in "EA"
    And I am on the "local_aicoursebuilder > index" page
    Then I should see "A course about solar energy" in the "[data-region='aicb-jobs-table']" "css_element"
    And I should not see "A course about hydrogen"
    And I should not see "7.5000"

  Scenario: The list is reached from the primary navigation by those who may use the plugin
    Given I log in as "teacher1"
    Then I should see "AI Course Builder" in the ".primary-navigation" "css_element"
    When I click on "AI Course Builder" "link" in the ".primary-navigation" "css_element"
    Then I should see "Course generations"
    And I should see "A course about solar energy"

  Scenario: Somebody who may not use the plugin is not offered it
    Given I log in as "student1"
    Then I should not see "AI Course Builder" in the ".primary-navigation" "css_element"

  Scenario: A job opens its page, and a job that is ready opens its blueprint
    Given a generated blueprint for "A course about renewable energy" is ready for review by "admin"
    And I log in as "admin"
    And I am on the "local_aicoursebuilder > index" page
    When I click on "A course about renewable energy" "link"
    Then I should see "Course generation"
    And I should see "A course about renewable energy"

  Scenario: A manager can switch to the jobs of everybody, and back
    Given I log in as "admin"
    When I am on the "local_aicoursebuilder > index" page
    Then I should see "There is no course generation here yet."
    When I click on "Show the jobs of all users" "link"
    Then I should see "A course about solar energy" in the "[data-region='aicb-jobs-table']" "css_element"
    And I should see "A course about databases" in the "[data-region='aicb-jobs-table']" "css_element"
    And I should see "Ana Popescu" in the "[data-region='aicb-jobs-table']" "css_element"
    And I should see "Dan Ionescu" in the "[data-region='aicb-jobs-table']" "css_element"
    When I click on "Show only my jobs" "link"
    Then I should see "There is no course generation here yet."

  @javascript
  Scenario: A new course is started from the list
    Given I log in as "teacher1"
    And I am on the "local_aicoursebuilder > index" page
    When I click on "New course" "link"
    Then I should see "Step 1 of 4"

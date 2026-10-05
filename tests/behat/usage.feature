@local @local_aicoursebuilder
Feature: Follow the AI spending and the cost limits
  In order to keep the cost of the generation under control
  As a manager
  I need to see what was spent, be warned when a limit is near, and let a stopped job go on once the limit is raised

  Background:
    Given the following config values are set as admin:
      | defaultconnector | fake | local_aicoursebuilder |
      | joblimitusd      | 0    | local_aicoursebuilder |
      | userlimitusd     | 10   | local_aicoursebuilder |
      | sitelimitusd     | 0    | local_aicoursebuilder |
      | alertpercent     | 80   | local_aicoursebuilder |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Ana       | Popescu  | teacher1@example.com |
      | teacher2 | Dan       | Ionescu  | teacher2@example.com |
    And "teacher1" has spent 8.5 USD on AI this month for "A course about solar energy"
    And "teacher2" has spent 1.25 USD on AI this month for "A course about databases"

  Scenario: The usage report shows what was spent per job, user, model and month
    Given I log in as "admin"
    When I am on the "local_aicoursebuilder > usage" page
    Then I should see "AI usage"
    And I should see "9.7500 USD" in the "[data-region='aicb-usage-totals']" "css_element"
    And I should see "A course about solar energy" in the "[data-region='aicb-usage-jobs']" "css_element"
    And I should see "8.5000" in the "[data-region='aicb-usage-jobs']" "css_element"
    And I should see "Ana Popescu" in the "[data-region='aicb-usage-users']" "css_element"
    And I should see "Dan Ionescu" in the "[data-region='aicb-usage-users']" "css_element"
    And I should see "deepseek-flash" in the "[data-region='aicb-usage-models']" "css_element"
    And I should see "9.7500" in the "[data-region='aicb-usage-months']" "css_element"

  Scenario: A user who has used most of the monthly limit is flagged
    Given I log in as "admin"
    When I am on the "local_aicoursebuilder > usage" page
    Then I should see "At least one limit has reached its alert percentage or is used up." in the "[data-region='aicb-usage-alert']" "css_element"
    And I should see "85%" in the "[data-region='aicb-usage-limits']" "css_element"
    And I should see "12%" in the "[data-region='aicb-usage-limits']" "css_element"

  Scenario: A limit that is used up is shown as such
    Given the following config values are set as admin:
      | userlimitusd | 8 | local_aicoursebuilder |
    And I log in as "admin"
    When I am on the "local_aicoursebuilder > usage" page
    Then I should see "Used up" in the "[data-region='aicb-usage-limits']" "css_element"

  @javascript
  Scenario: The report can be widened to all time
    Given I log in as "admin"
    And I am on the "local_aicoursebuilder > usage" page
    When I set the field "Period" to "All time"
    And I press "Show"
    Then I should see "9.7500 USD" in the "[data-region='aicb-usage-totals']" "css_element"
    And I should see "A course about solar energy" in the "[data-region='aicb-usage-jobs']" "css_element"

  @javascript
  Scenario: A job that a cost limit paused is resumed by its owner once the limit is raised
    Given "admin" has a job for "A course about wind turbines" that a cost limit paused
    And I log in as "admin"
    When I am on the "A course about wind turbines" "local_aicoursebuilder > job" page
    Then I should see "The generation was paused by a cost limit." in the "[data-region='aicb-paused']" "css_element"
    And I should see "The AI cost limit of the job was reached" in the "[data-region='aicb-paused']" "css_element"
    When I press "Resume"
    Then I should see "Waiting for the server to start the job"
    And I run all adhoc tasks
    And I wait until "[data-region='aicb-finished']" "css_element" exists
    And I should see "The blueprint is ready for review."

@local @local_aicoursebuilder @javascript
Feature: Review, edit and approve the generated blueprint
  In order to get a course I am happy with
  As a teacher
  I need to correct what was generated, and approve the version the course is built from

  Background:
    Given a generated blueprint for "A course about renewable energy" is ready for review by "admin"
    And I log in as "admin"
    And I am on the "A course about renewable energy" "local_aicoursebuilder > review" page

  Scenario: The generated course is shown as a tree, with the first section open for editing
    Then I should see "Review the course blueprint"
    And I should see "Version 1 · saved"
    And I should see "Noțiuni de bază" in the "[data-region='aicb-editor-tree']" "css_element"
    And I should see "Ce este energia regenerabilă" in the "[data-region='aicb-editor-tree']" "css_element"
    And the field "Title" matches value "Noțiuni de bază"

  Scenario: A part the generation marked for checking is shown, and can be marked as checked
    Then I should see "Check" in the "[data-region='aicb-flag']" "css_element"
    When I click on "Ce este energia regenerabilă" "button"
    Then I should see "The generation marked this part for you to check."
    When I click on "Mark as checked" "button"
    Then I should not see "The generation marked this part for you to check."
    And "[data-region='aicb-flag']" "css_element" should not exist
    And I should see "unsaved changes"

  Scenario: An edit is saved as a new version
    When I set the field "Title" to "Noțiuni esențiale"
    Then I should see "unsaved changes"
    And I should see "Noțiuni esențiale" in the "[data-region='aicb-editor-tree']" "css_element"
    When I press "Save"
    Then I should see "The changes were saved as a new version."
    And I should see "Version 2 · saved"

  Scenario: A part can be moved down and deleted
    When I click on "Move down" "button"
    Then I should see "Surse de energie în practică" in the "(//ul[@data-region='aicb-tree-list']/li)[1]" "xpath_element"
    When I click on "Bun venit" "button"
    And I click on "Delete" "button"
    Then I should not see "Bun venit" in the "[data-region='aicb-editor-tree']" "css_element"
    And I should see "unsaved changes"

  Scenario: A blueprint with errors is saved but cannot be approved
    When I set the field "Title" to ""
    And I press "Save"
    Then I should see "The changes were saved, but the blueprint still has errors."
    And I should see "The blueprint has errors that must be fixed before it can be approved:"
    When I press "Approve"
    And I click on "Approve" "button" in the "Approve blueprint" "dialogue"
    Then I should see "The blueprint cannot be approved while it has errors"

  Scenario: The approved version is locked
    When I set the field "Title" to "Noțiuni esențiale"
    And I press "Approve"
    And I click on "Approve" "button" in the "Approve blueprint" "dialogue"
    Then I should see "The blueprint was approved."
    And I should see "approved" in the "[data-region='aicb-editor-status']" "css_element"
    When I reload the page
    Then I should see "This blueprint was approved and can no longer be changed."
    And "Save" "button" should not exist
    And the field "Title" matches value "Noțiuni esențiale"

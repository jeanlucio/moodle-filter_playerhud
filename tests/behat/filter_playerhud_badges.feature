@filter @filter_playerhud @filter_playerhud_badges @javascript
Feature: PlayerHUD filter drop card badges
  As a student viewing course content with PlayerHUD shortcodes
  I need the drop card's collection-count badge to reflect what I actually collected

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | topics |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    And I add the "PlayerHUD" block
    And a PlayerHUD item "Badge Gem" with drop code "BADGE1" and collection limit 3 exists in course "C1"
    And a label with shortcode "[PLAYERHUD_DROP code=BADGE1]" exists in the course
    And I log out

  # Regression guard: the card's visible progress badge ("1 of 3") is rendered server-side and
  # is a separate DOM element from the data-progress-text attribute that filter_collect.js
  # already refreshed after a successful AJAX collect — the badge itself used to keep showing
  # the pre-collection count until the page was reloaded, even though the collection succeeded.
  Scenario: Collecting a drop refreshes the card's visible progress badge without a page reload
    Given I log in as "student1"
    And I am on "Course 1" course homepage
    And I should see "0 of 3" in the ".ph-badge-progress" "css_element"
    When I click on ".ph-action-collect" "css_element"
    And I wait for the PlayerHUD AJAX collect to complete
    Then I should see "1 of 3" in the ".ph-badge-progress" "css_element"

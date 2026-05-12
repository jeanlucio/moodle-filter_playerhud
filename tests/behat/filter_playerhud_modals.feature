@filter @filter_playerhud @filter_playerhud_modals @javascript
Feature: PlayerHUD filter modal behaviour
  As a student viewing course content with PlayerHUD shortcodes
  I need the filter modals to work correctly without redirecting the page

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
    And a PlayerHUD item "Test Gem" with drop code "GEM01" exists in course "C1"
    And I log out

  # -----------------------------------------------------------------
  # Shortcode de coleta — AJAX não redireciona
  # -----------------------------------------------------------------

  Scenario: Collecting a drop via filter shortcode does not redirect the page
    Given I log in as "student1"
    And I am on "Course 1" course homepage
    And I remember the current page URL
    And I click on ".ph-action-collect" "css_element"
    And I wait for the PlayerHUD AJAX collect to complete
    Then the page URL has not changed

  # -----------------------------------------------------------------
  # Modal de detalhes via widget stash — abre corretamente
  # -----------------------------------------------------------------

  Scenario: Student opens item details from the widget stash after collecting
    Given "student1" has collected drop "GEM01" in course "C1"
    When I log in as "student1"
    And I am on "Course 1" course homepage
    And I click on the first ".ph-item-trigger" element
    Then the PlayerHUD item details modal is visible
    And I should see "Test Gem" in the PlayerHUD modal

  # -----------------------------------------------------------------
  # Descrição do item — não deve exibir HTML raw
  # -----------------------------------------------------------------

  Scenario: Item description in the modal does not render raw HTML tags
    Given "student1" has collected drop "GEM01" in course "C1"
    When I log in as "student1"
    And I am on "Course 1" course homepage
    And I click on the first ".ph-item-trigger" element
    Then the PlayerHUD item details modal is visible
    And I should not see "<p dir=" in the PlayerHUD modal
    And I should not see "style=text-align" in the PlayerHUD modal

  # -----------------------------------------------------------------
  # Strings — não devem mostrar placeholders [[...]]
  # -----------------------------------------------------------------

  Scenario: Filter modal does not display raw string placeholders
    Given "student1" has collected drop "GEM01" in course "C1"
    When I log in as "student1"
    And I am on "Course 1" course homepage
    And I click on the first ".ph-item-trigger" element
    Then the PlayerHUD item details modal is visible
    And I should not see "[[" in the PlayerHUD modal
    And I should not see "]]" in the PlayerHUD modal

  # -----------------------------------------------------------------
  # Múltiplos cliques — modal não duplica no DOM
  # -----------------------------------------------------------------

  Scenario: Clicking item trigger multiple times does not duplicate the modal in DOM
    Given "student1" has collected drop "GEM01" in course "C1"
    When I log in as "student1"
    And I am on "Course 1" course homepage
    And I click on the first ".ph-item-trigger" element
    And the PlayerHUD item details modal is visible
    And I close the PlayerHUD modal
    And I click on the first ".ph-item-trigger" element
    Then the PlayerHUD item details modal is visible
    And there is only one PlayerHUD modal in the DOM

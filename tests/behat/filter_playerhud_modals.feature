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
    And a label with shortcode "[PLAYERHUD_DROP code=GEM01]" exists in the course
    And I log out

  Scenario: Collecting a drop via filter shortcode does not redirect the page
    Given I log in as "student1"
    And I am on "Course 1" course homepage
    And I remember the current page URL
    And I click on ".ph-action-collect" "css_element"
    And I wait for the PlayerHUD AJAX collect to complete
    Then the page URL has not changed

  Scenario: Student opens item details from the widget stash after collecting
    Given "student1" has collected drop "GEM01" in course "C1"
    When I log in as "student1"
    And I am on "Course 1" course homepage
    And I click on the first ".ph-item-trigger" element
    Then the PlayerHUD item details modal is visible
    And I should see "Test Gem" in the PlayerHUD modal

  # NOTE: "I should not see" checks rendered VISIBLE TEXT (Mink getText()), not the DOM's
  # actual HTML/attributes. A dangerous attribute like onerror="..." never shows up as
  # visible text whether it was safely escaped or unsafely injected as live HTML, so this
  # scenario alone does not prove script-execution safety — it only proves that raw editor
  # markup (e.g. an unparsed "<p dir=" from a WYSIWYG paste) does not leak as literal text.
  # See "does not leak a script payload" below for the HTML-level regression test.
  Scenario: Item description in the modal does not render raw HTML tags
    Given "student1" has collected drop "GEM01" in course "C1"
    When I log in as "student1"
    And I am on "Course 1" course homepage
    And I click on the first ".ph-item-trigger" element
    Then the PlayerHUD item details modal is visible
    And I should not see "<p dir=" in the PlayerHUD modal
    And I should not see "style=text-align" in the PlayerHUD modal

  # Regression guard: item descriptions must be sanitized before being injected as live
  # HTML in the modal, so a script/event-handler payload stored in a description cannot
  # execute.
  #
  # Uses .ph-drop-trigger-area, NOT .ph-item-trigger: the latter is the block sidebar
  # stash's own trigger, bound to a separate script (view.js) whenever the block is on the
  # page (see filter_collect.js's own guard around .ph-item-trigger) and would silently
  # test the wrong code path. .ph-drop-trigger-area is unique to the drop card rendered by
  # THIS plugin's render_drop() / drop.mustache, and is unconditionally bound to
  # filter_collect.js's modal handler — the path that renders the description as HTML.
  #
  # Scoped by [data-name="Evil Gem"] rather than "the first" match: the Background above
  # already creates its own GEM01 drop card on the same page, and "first .ph-drop-trigger-
  # area element" silently clicked THAT one instead of this scenario's own card in an
  # earlier version of this test — a false pass, since GEM01 has no description at all.
  Scenario: Item description with a script payload does not leak into the modal HTML
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And a PlayerHUD item "Evil Gem" with drop code "XSSGEM" and description "<img src=x onerror='window.playerhudXssFired=true'>Safe text" exists in course "C1"
    And a label with shortcode "[PLAYERHUD_DROP code=XSSGEM]" exists in the course
    And I log out
    And "student1" has collected drop "XSSGEM" in course "C1"
    When I log in as "student1"
    And I am on "Course 1" course homepage
    And I click on the first "[data-name='Evil Gem'] .ph-drop-trigger-area" element
    Then the PlayerHUD item details modal is visible
    And the PlayerHUD filter modal description HTML should not contain "onerror"
    And the PlayerHUD filter modal description HTML should not contain "<script"

  Scenario: Filter modal does not display raw string placeholders
    Given "student1" has collected drop "GEM01" in course "C1"
    When I log in as "student1"
    And I am on "Course 1" course homepage
    And I click on the first ".ph-item-trigger" element
    Then the PlayerHUD item details modal is visible
    And I should not see "[[" in the PlayerHUD modal
    And I should not see "]]" in the PlayerHUD modal

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

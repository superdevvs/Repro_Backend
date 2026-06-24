# Requirements Document

## Introduction

This feature delivers a role-aware onboarding tour experience for the four team roles (photographer, salesRep, editing_manager, editor) by generalizing the existing client onboarding implementation into a single role-aware hook, component, and event module parameterized by a role key and version. Each team role receives an auto-opening welcome dialog on first eligible load, a guided spotlight tour with role-specific steps anchored to `data-onboarding-target` markers on the role's dashboard view, and a consistent "Take tour" replay button.

In addition, a replay cap is applied uniformly to all five roles (client plus the four team roles): a user may replay a completed tour at most three times. Replay counts are tracked through a new server-persisted `replayCount` field within each role's onboarding preference block, saved through `PUT /api/profile`. Once the cap is reached, the prominent sidebar "Take tour" button is hidden and the replay action is relocated to the Settings page. Backend validation for `PUT /api/profile` is extended so the `replayCount` field is accepted (and not stripped) for all five onboarding keys. Existing client tour behavior is preserved for backward compatibility, including the localStorage fallback.

## Glossary

- **Onboarding_System**: The frontend role-aware onboarding subsystem comprising the role-aware hook, the role-aware onboarding component, and the role-aware event module that render and control the welcome dialog, guided tour, and replay controls.
- **Profile_API**: The backend endpoint `PUT /api/profile` that validates and persists user preferences, including each role's onboarding state block.
- **Role_Key**: The identifier used to select onboarding configuration and the preference storage key. Valid values are `client`, `photographer`, `salesRep`, `editing_manager`, and `editor`.
- **Onboarding_Key**: The `metadata.preferences` property name storing a role's onboarding state. The client role uses the legacy key `clientDashboardOnboarding`; the team roles use `photographerDashboardOnboarding`, `salesRepDashboardOnboarding`, `editingManagerDashboardOnboarding`, and `editorDashboardOnboarding`.
- **Onboarding_State**: The persisted object for a role containing the lifecycle fields `eligible`, `version`, `createdAt`, `startedAt`, `completedAt`, `dismissedAt`, `lastStep`, `source`, and the new `replayCount` field.
- **Welcome_Dialog**: The auto-opening introductory dialog presented to an eligible user who has not started, completed, or dismissed the tour.
- **Guided_Tour**: The spotlight overlay that walks the user through role-specific steps anchored to `data-onboarding-target` markers.
- **Replay_Button**: The prominent sidebar "Take tour" control that re-opens the Guided_Tour.
- **Settings_Replay_Entry**: The "Replay dashboard tour" control surfaced on the Settings page.
- **Replay_Cap**: The maximum number of replays permitted per user per role, set to three.
- **Replay_Count**: The integer `replayCount` field within Onboarding_State recording how many times the user has replayed the completed tour.
- **Dashboard_View**: The role-specific dashboard React view component: `PhotographerDashboardView`, `SalesDashboardView`, `EditingManagerDashboardView`, `EditorDashboardView`, and the existing client dashboard view.
- **Onboarding_Target_Marker**: A `data-onboarding-target` attribute applied to a dashboard element that anchors a Guided_Tour step.
- **LocalStorage_Fallback**: The browser localStorage persistence mechanism used to mirror Onboarding_State when the authenticated save path is unavailable.

## Requirements

### Requirement 1: Role-aware onboarding generalization

**User Story:** As a developer, I want the client onboarding hook, component, and events generalized into a role-aware implementation, so that all five roles share one maintained code path instead of duplicated per-role copies.

#### Acceptance Criteria

1. THE Onboarding_System SHALL accept a Role_Key and a version as configuration inputs that select the role's onboarding steps, Onboarding_Key, and copy.
2. WHERE the Role_Key is `client`, THE Onboarding_System SHALL read and write Onboarding_State using the Onboarding_Key `clientDashboardOnboarding`.
3. WHERE the Role_Key is `photographer`, `salesRep`, `editing_manager`, or `editor`, THE Onboarding_System SHALL read and write Onboarding_State using the Onboarding_Key `photographerDashboardOnboarding`, `salesRepDashboardOnboarding`, `editingManagerDashboardOnboarding`, or `editorDashboardOnboarding` respectively.
4. THE Onboarding_System SHALL read the current Onboarding_State from `metadata.preferences.{Onboarding_Key}`.
5. THE Onboarding_System SHALL include `replayCount` in the Onboarding_State type used by the hook.

### Requirement 2: Welcome dialog behavior

**User Story:** As a photographer, salesRep, editing_manager, or editor, I want a welcome dialog when I first load my dashboard, so that I am introduced to the tour.

#### Acceptance Criteria

1. WHEN a user with Onboarding_State where `eligible` is true and `startedAt`, `completedAt`, and `dismissedAt` are all absent loads the user's Dashboard_View, THE Onboarding_System SHALL open the Welcome_Dialog.
2. WHEN the user selects the start action in the Welcome_Dialog, THE Onboarding_System SHALL close the Welcome_Dialog, open the Guided_Tour, and persist `startedAt` with the current timestamp and `lastStep` of 0.
3. WHEN the user selects the skip action in the Welcome_Dialog, THE Onboarding_System SHALL close the Welcome_Dialog and persist `dismissedAt` with the current timestamp.
4. IF the Onboarding_State has a non-absent `startedAt`, `completedAt`, or `dismissedAt`, THEN THE Onboarding_System SHALL keep the Welcome_Dialog closed on dashboard load.

### Requirement 3: Role-specific guided tour and target markers

**User Story:** As a team-role user, I want a guided tour that highlights the relevant areas of my dashboard, so that I learn where key features are located.

#### Acceptance Criteria

1. WHERE the Role_Key is `photographer`, `salesRep`, `editing_manager`, or `editor`, THE Onboarding_System SHALL render Guided_Tour steps defined for that Role_Key.
2. THE Dashboard_View for each team Role_Key SHALL include an Onboarding_Target_Marker for every Guided_Tour step defined for that Role_Key.
3. WHEN the Guided_Tour displays a step, THE Onboarding_System SHALL position the spotlight over the element identified by that step's Onboarding_Target_Marker.
4. WHEN the user advances or returns between steps, THE Onboarding_System SHALL persist the current step index as `lastStep`.
5. WHEN the user reaches and confirms the final step, THE Onboarding_System SHALL close the Guided_Tour and persist `completedAt` with the current timestamp.

### Requirement 4: Consistent replay button across all roles

**User Story:** As a user of any of the five roles, I want a consistent "Take tour" button, so that I can re-open the tour from the same place regardless of role.

#### Acceptance Criteria

1. WHILE the user's Replay_Count is below the Replay_Cap and the Welcome_Dialog and Guided_Tour are both closed, THE Onboarding_System SHALL display the Replay_Button for all five Role_Keys.
2. WHEN the user selects the Replay_Button, THE Onboarding_System SHALL open the Guided_Tour at the first step.
3. WHEN the user completes a Guided_Tour that was opened through the Replay_Button, THE Onboarding_System SHALL increment Replay_Count by 1 and persist the updated Onboarding_State.

### Requirement 5: Replay cap enforcement and relocation

**User Story:** As a product owner, I want replays limited to three and relocated to Settings afterward, so that the prominent tour control does not clutter the dashboard indefinitely.

#### Acceptance Criteria

1. THE Onboarding_System SHALL set the Replay_Cap to 3 for all five Role_Keys.
2. WHILE the Replay_Count is greater than or equal to the Replay_Cap, THE Onboarding_System SHALL hide the Replay_Button.
3. WHILE the Replay_Count is greater than or equal to the Replay_Cap, THE Onboarding_System SHALL display the Settings_Replay_Entry on the Settings page.
4. WHEN the user selects the Settings_Replay_Entry, THE Onboarding_System SHALL open the Guided_Tour at the first step.
5. IF the Replay_Count is greater than or equal to the Replay_Cap, THEN THE Onboarding_System SHALL not increment Replay_Count when the Guided_Tour is completed again.

### Requirement 6: replayCount persistence

**User Story:** As a user, I want my replay count remembered across sessions and devices, so that the cap is enforced consistently.

#### Acceptance Criteria

1. WHEN the Onboarding_System persists Onboarding_State, THE Onboarding_System SHALL include the current `replayCount` value in the `PUT /api/profile` request body under the role's Onboarding_Key.
2. WHERE the persisted Onboarding_State has no `replayCount`, THE Onboarding_System SHALL treat the Replay_Count as 0.
3. WHEN the Onboarding_System reads Onboarding_State on load, THE Onboarding_System SHALL use the persisted `replayCount` value to evaluate the Replay_Cap.

### Requirement 7: Backend replayCount validation

**User Story:** As a developer, I want the backend to accept replayCount, so that the field is saved instead of being stripped from the onboarding block.

#### Acceptance Criteria

1. THE Profile_API SHALL accept a `replayCount` field within each of the five Onboarding_Key blocks (`clientDashboardOnboarding`, `photographerDashboardOnboarding`, `salesRepDashboardOnboarding`, `editingManagerDashboardOnboarding`, `editorDashboardOnboarding`).
2. THE Profile_API SHALL validate `replayCount` as a nullable integer within the range 0 to 100 inclusive.
3. WHEN a request includes a valid `replayCount` within an Onboarding_Key block, THE Profile_API SHALL persist the `replayCount` value with the rest of the Onboarding_State.
4. IF a request includes a `replayCount` outside the range 0 to 100 or of a non-integer type, THEN THE Profile_API SHALL reject the request with a validation error.

### Requirement 8: Backward compatibility and localStorage fallback

**User Story:** As an existing client user, I want my current tour behavior preserved, so that the generalization does not regress the established experience.

#### Acceptance Criteria

1. THE Onboarding_System SHALL preserve the existing client tour lifecycle behavior for the `client` Role_Key, including welcome, start, dismiss, complete, and progress actions.
2. THE Onboarding_System SHALL merge the LocalStorage_Fallback Onboarding_State over the profile Onboarding_State when computing the active Onboarding_State for any Role_Key.
3. WHEN the Onboarding_System persists Onboarding_State, THE Onboarding_System SHALL write the Onboarding_State to the LocalStorage_Fallback keyed by Role_Key and user identifier.
4. IF no authentication token is available, THEN THE Onboarding_System SHALL persist Onboarding_State to the LocalStorage_Fallback and skip the `PUT /api/profile` request.

### Requirement 9: Graceful handling of missing or ineligible state

**User Story:** As a user whose onboarding state is missing or ineligible, I want the dashboard to render without onboarding prompts, so that the experience is unobtrusive.

#### Acceptance Criteria

1. IF the Onboarding_State is absent or not an object, THEN THE Onboarding_System SHALL treat the Onboarding_State as empty and render the Dashboard_View without the Welcome_Dialog and without the Guided_Tour.
2. IF the Onboarding_State has `eligible` of false or absent, THEN THE Onboarding_System SHALL keep the Welcome_Dialog closed and hide the Replay_Button.
3. IF a Guided_Tour step's Onboarding_Target_Marker is not present in the DOM, THEN THE Onboarding_System SHALL render the step's instructional card without a spotlight overlay for that step.

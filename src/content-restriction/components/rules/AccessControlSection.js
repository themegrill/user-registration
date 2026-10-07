/**
 * External Dependencies
 */
import React, { useRef, useEffect, act } from "react";
import { __ } from "@wordpress/i18n";
import ContentTypeDropdown from "../dropdowns/ContentTypeDropdown";
import ContentValueInput from "../inputs/ContentValueInput";
import DropdownButton from "../dropdowns/DropdownButton";
import {
	isDripContent,
	isMasteriyo,
	isProAccess
} from "../../utils/localized-data";
import DripThisContent from "../content-drip/DripThisContent";

const AccessControlSection = ({
	accessControl = "access",
	onAccessControlChange,
	contentTargets = [],
	onContentTargetsChange,
	ruleType = null,
	rule = null,
	conditions
}) => {
	const conditionValueInputWrapperRef = useRef(null);
	const lastRuleTypeRef = useRef(null);

	const handleAfterContentTypeSelection = (option) => {
		const newContentTarget = {
			id: `x${Date.now()}`,
			type: option.value,
			label: option.label,
			value: option.value === "whole_site" ? "whole_site" : [],
			taxonomy: option.value === "taxonomy" ? "" : undefined,
			drip: {
				activeType: "fixed_date",
				value: {
					fixed_date: { date: "", time: "" },
					days_after: { days: 0 }
				}
			}
		};
		onContentTargetsChange([...contentTargets, newContentTarget]);
	};

	const handleContentTargetUpdate = (targetId, newValue) => {
		const updatedTargets = contentTargets.map((target) =>
			target.id === targetId ? { ...target, value: newValue } : target
		);
		onContentTargetsChange(updatedTargets);
	};

	const handleContentTargetRemove = (targetId) => {
		const updatedTargets = contentTargets.filter(
			(target) => target.id !== targetId
		);
		onContentTargetsChange(updatedTargets);
	};

	// Handle correction of access control for membership rules on mount and when ruleType changes
	useEffect(() => {
		const isMembershipRule = ruleType === "membership";
		const ruleTypeChanged = lastRuleTypeRef.current !== ruleType;

		// Update ref to track ruleType changes
		if (ruleTypeChanged) {
			lastRuleTypeRef.current = ruleType;
		}

		// For membership rules, always force access control to "access"
		// Only correct on mount (when ruleType changes) or if value is wrong
		if (isMembershipRule && accessControl !== "access") {
			// Only update if ruleType just changed (initial load or type change) to prevent loops
			if (ruleTypeChanged) {
				onAccessControlChange("access");
			}
			return;
		}

		// For non-membership rules, if not pro and accessControl is "restrict", force to "access"
		// Only correct when ruleType changes to prevent infinite loops
		if (
			!isMembershipRule &&
			!isProAccess() &&
			accessControl === "restrict" &&
			ruleTypeChanged
		) {
			onAccessControlChange("access");
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ruleType]); // Only depend on ruleType to prevent loops

	useEffect(() => {
		if (conditionValueInputWrapperRef.current) {
			const isMembershipRule = ruleType === "membership";
			// For membership rules, always use access class even if accessControl is "restrict"
			const effectiveAccessControl = isMembershipRule
				? "access"
				: accessControl;

			if (effectiveAccessControl === "access") {
				conditionValueInputWrapperRef.current.classList.add(
					"urcr-access-content"
				);
				conditionValueInputWrapperRef.current.classList.remove(
					"urcr-restrict-content"
				);
			} else {
				conditionValueInputWrapperRef.current.classList.add(
					"urcr-restrict-content"
				);
				conditionValueInputWrapperRef.current.classList.remove(
					"urcr-access-content"
				);
			}
		}
	}, [accessControl, ruleType]);

	const handleAccessControlChange = (option) => {
		const newValue = option.value;
		const isMembershipRule = ruleType === "membership";

		// For membership rules, never allow restrict option
		if (isMembershipRule && newValue === "restrict") {
			return;
		}

		// For non-membership rules, if not pro and trying to set restrict, prevent it
		if (!isMembershipRule && !isProAccess() && newValue === "restrict") {
			return;
		}

		if (conditionValueInputWrapperRef.current) {
			if (newValue === "access") {
				conditionValueInputWrapperRef.current.classList.add(
					"urcr-access-content"
				);
				conditionValueInputWrapperRef.current.classList.remove(
					"urcr-restrict-content"
				);
			} else {
				conditionValueInputWrapperRef.current.classList.add(
					"urcr-restrict-content"
				);
				conditionValueInputWrapperRef.current.classList.remove(
					"urcr-access-content"
				);
			}
		}

		onAccessControlChange(newValue);
	};

	const getAccessControlLabel = () => {
		const isMembershipRule = ruleType === "membership";

		// For membership rules, always show "Access" even if accessControl is "restrict"
		if (isMembershipRule) {
			return __("Access", "user-registration");
		}

		if (accessControl === "restrict") {
			return __("Restrict", "user-registration");
		}
		return __("Access", "user-registration");
	};

	const getAccessControlOptions = () => {
		const isMembershipRule = ruleType === "membership";

		// For membership rules, never show restrict option (neither for free nor pro)
		if (isMembershipRule) {
			return [
				{ value: "access", label: __("Access", "user-registration") }
			];
		}

		return [
			...(isProAccess()
				? [
						{
							value: "restrict",
							label: __("Restrict", "user-registration")
						}
					]
				: []),
			{ value: "access", label: __("Access", "user-registration") }
		].map((option) => ({
			...option,
			disabled: !isProAccess() && option.value === "restrict"
		}));
	};

	const isMembershipRule = ruleType === "membership";

	/**
	 * Explain how this rule combines with other rules, since a Whole Site rule
	 * never lifts a restriction from a page or post rule. Only covers the target
	 * types this has been verified for; others are enforced by separate paths.
	 *
	 * @return {string} Hint text, or an empty string when no hint applies.
	 */
	const getAccessControlHint = () => {
		const targetTypes = contentTargets.map((target) => target.type);
		const isRestrict = !isMembershipRule && accessControl === "restrict";

		if (targetTypes.includes("whole_site")) {
			return isRestrict
				? __(
						"Users who match these conditions are restricted from the site, unless another rule allows them.",
						"user-registration"
					)
				: __(
						"Only users who match these conditions can view the site, unless another rule allows them.",
						"user-registration"
					);
		}

		const onlyPagesOrPosts =
			targetTypes.length > 0 &&
			targetTypes.every((type) =>
				["wp_pages", "wp_posts", "pages", "posts"].includes(type)
			);

		if (!onlyPagesOrPosts) {
			return "";
		}

		return isRestrict
			? __(
					"Users who match these conditions are restricted from this content, unless another rule for the same content allows them. A Whole Site rule does not override this.",
					"user-registration"
				)
			: __(
					"Only users who match these conditions can view this content, unless another rule for the same content allows them. A Whole Site rule does not override this.",
					"user-registration"
				);
	};

	const accessControlHint = getAccessControlHint();

	return (
		<div className="urcr-target-selection-section ur-d-flex ur-align-items-start">
			{/* Access/Restrict Section */}
			<div
				className="urcr-condition-value-input-wrapper"
				ref={conditionValueInputWrapperRef}
			>
				{isMembershipRule ? (
					// For membership rules, show only "Access" text without dropdown
					<span className="urcr-access-control-button urcr-condition-value-input urcr-dropdown-button">
						<span className="urcr-dropdown-button-text">
							{__("Access", "user-registration")}
						</span>
					</span>
				) : (
					<DropdownButton
						buttonContent={
							<>
								<span className="urcr-dropdown-button-text">
									{getAccessControlLabel()}
								</span>
								<span className="urcr-dropdown-button-arrow dashicons dashicons-arrow-down-alt2"></span>
							</>
						}
						options={getAccessControlOptions()}
						value={accessControl}
						onSelect={handleAccessControlChange}
						buttonClassName="urcr-access-control-button urcr-condition-value-input"
						wrapperClassName="urcr-access-control-dropdown-wrapper"
					/>
				)}
			</div>

			<span className="urcr-arrow-icon" aria-hidden="true"></span>
			<div className="ur-d-flex ur-flex-column">
				{contentTargets.length > 0 && (
					<div className="urcr-target-type-group">
						{contentTargets.map((target) => {
							// For whole_site, use "Includes" as label prefix, otherwise use target.label
							const displayLabel =
								target.type === "whole_site"
									? __("Includes", "user-registration")
									: target.label;
							return (
								<div
									key={target.id}
									className="urcr-target-item"
									style={{
										display:
											target.type ===
												"masteriyo_courses" &&
											!isMasteriyo()
												? "none"
												: ""
									}}
								>
									<span className="urcr-target-type-label">
										{displayLabel
											.replace(/_/g, " ")
											.replace(/\b\w/g, (char) =>
												char.toUpperCase()
											)}
										:
									</span>
									<div className="urcr-target-item--content-wrapper">
										<ContentValueInput
											contentType={target.type}
											value={target.value}
											onChange={(newValue) =>
												handleContentTargetUpdate(
													target.id,
													newValue
												)
											}
										/>
										<div className="urcr-target-item--content-buttons">
											{isProAccess() &&
												isDripContent() &&
												"membership" === ruleType && (
													<DripThisContent
														onContentTargetsChange={
															onContentTargetsChange
														}
														contentTargets={contentTargets}
														target={target}
													/>
												)}
																						
											<button
												type="button"
												className="button-link urcr-target-remove"
												onClick={() =>
													handleContentTargetRemove(target.id)
												}
												aria-label={__(
													"Remove",
													"user-registration"
												)}
											>
												<span className="dashicons dashicons-no-alt"></span>
											</button>
										</div>
									</div>
								</div>
							);
						})}
					</div>
				)}

				{/* Always show + Content button */}
				<DropdownButton
					buttonContent={
						<>
							<span className="dashicons dashicons-plus-alt2"></span>
							{__("Content", "user-registration")}
						</>
					}
					options={[]}
					onSelect={handleAfterContentTypeSelection}
					buttonClassName="button urcr-add-content-button"
					wrapperClassName="urcr-content-dropdown-wrapper"
					renderDropdown={() => (
						<ContentTypeDropdown
							onSelect={handleAfterContentTypeSelection}
							existingContentTypes={contentTargets}
							conditions={conditions}
							accessControl={accessControl}
						/>
					)}
				/>

				{accessControlHint && (
					<p
						className="description urcr-access-control-hint"
						style={{ padding: "0 24px", margin: "12px 0 0" }}
					>
						{accessControlHint}
					</p>
				)}
			</div>
		</div>
	);
};

export default AccessControlSection;

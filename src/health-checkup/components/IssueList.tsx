import { Box, Collapse, Flex, useDisclosure } from "@chakra-ui/react";
import { __, _n, sprintf } from "@wordpress/i18n";
import { FiAlertCircle, FiChevronDown } from "react-icons/fi";
import { HealthCheck } from "../api/healthCheckupApi";
import { COLOR, TYPE } from "../tokens";
import { LEGACY_BUTTON_OPT_OUT } from "../utils/legacyButtonOptOut";
import RichText from "./RichText";
import Text from "./Text";

/**
 * A list of findings with their remedies, shared by all three result screens.
 * The label is a prop: optional "suggested improvements" on a delivered result,
 * things to fix on a failed one.
 */
const IssueList = ({
	issues,
	label,
	defaultOpen = true,
}: {
	issues: HealthCheck[];
	label?: string;
	/**
	 * Open unless told otherwise: where the message arrived these are the only
	 * thing left to read. The failed screen closes them, leading with its
	 * recommendation instead.
	 */
	defaultOpen?: boolean;
}) => {
	const { isOpen, onToggle } = useDisclosure({ defaultIsOpen: defaultOpen });

	if (issues.length === 0) {
		return null;
	}

	const heading =
		label ??
		sprintf(
			/* translators: %d: number of optional improvements the scan found */
			_n(
				"%d suggested improvement",
				"%d suggested improvements",
				issues.length,
				"user-registration"
			),
			issues.length
		);

	const chevron = (
		<Box
			flexShrink={0}
			display="flex"
			color="gray.400"
			transform={isOpen ? "rotate(180deg)" : undefined}
			transition="transform 180ms ease"
		>
			<FiChevronDown size={16} />
		</Box>
	);

	const itemContent = (issue: HealthCheck) => (
		<>
			<Text fontSize={TYPE.body} fontWeight="600" color={COLOR.title}>
				{issue.title}
			</Text>
			<Text fontSize={TYPE.body} color={COLOR.body} mt="3px" lineHeight="1.6">
				<RichText text={issue.fix || issue.message} />
			</Text>
		</>
	);

	// A marker each, so several findings read as several things rather than one
	// run-on block of title-then-remedy pairs.
	const items = (
		<Box as="ul" listStyleType="disc" pl="18px" m="0">
			{issues.map((issue, index) => (
				<Box as="li" key={issue.key} mt={index === 0 ? 0 : "12px"} color="gray.400">
					{itemContent(issue)}
				</Box>
			))}
		</Box>
	);

	return (
		<Box border="1px solid" borderColor="gray.200" bg="white" borderRadius="9px" mt="16px" overflow="hidden">
			<Flex
				as="button"
				type="button"
				className={LEGACY_BUTTON_OPT_OUT}
				onClick={onToggle}
				width="100%"
				align="center"
				gap="11px"
				p="12px 14px"
				textAlign="left"
				cursor="pointer"
				aria-expanded={isOpen}
				transition="background 140ms ease"
				_hover={{ bg: "gray.50" }}
			>
				<Flex
					flexShrink={0}
					w="26px"
					h="26px"
					borderRadius="full"
					bg="orange.50"
					align="center"
					justify="center"
					color="orange.700"
				>
					<FiAlertCircle size={14} />
				</Flex>

				<Text flex="1" fontSize={TYPE.body} fontWeight="600" color={COLOR.title}>
					{heading}
				</Text>

				{chevron}
			</Flex>

			<Collapse in={isOpen} animateOpacity>
				{/* Hairline, not a nested panel: the list belongs to its header. */}
				<Box borderTop="1px solid" borderColor="gray.200" p="13px 14px 14px">
					{items}
				</Box>
			</Collapse>
		</Box>
	);
};

export default IssueList;

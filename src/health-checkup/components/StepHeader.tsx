import { Box, Flex } from "@chakra-ui/react";
import { ReactNode } from "react";
import { COLOR, TYPE } from "../tokens";
import Text from "./Text";

/** Title and detail for a step. Position is shown by the bar, not here. */
const StepHeader = ({
	title,
	description,
	icon,
}: {
	title: ReactNode;
	description?: ReactNode;
	icon?: ReactNode;
}) => (
	<Box mb="22px">
		<Flex align="center" gap="12px">
			{icon}
			<Text as="h2" fontSize={TYPE.heading} fontWeight="600" letterSpacing="-0.01em" color={COLOR.title}>
				{title}
			</Text>
		</Flex>

		{description && (
			<Text fontSize={TYPE.body} lineHeight="1.62" color={COLOR.body} mt="11px">
				{description}
			</Text>
		)}
	</Box>
);

export default StepHeader;

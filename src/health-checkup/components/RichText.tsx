import { Text } from "@chakra-ui/react";
import { Fragment } from "react";

// Lightweight, safe markdown-lite renderer: no HTML is ever parsed, just two
// plain-text conventions — `code` and **bold** — split into React nodes.
const TOKEN_RE = /(`[^`]+`|\*\*[^*]+\*\*)/g;

const RichText = ({ text }: { text: string }) => {
	const parts = text.split(TOKEN_RE).filter((part) => part !== "");

	return (
		<>
			{parts.map((part, index) => {
				if (part.startsWith("`") && part.endsWith("`")) {
					return (
						<Text
							as="code"
							key={index}
							fontFamily="mono"
							fontSize="0.92em"
							bg="gray.50"
							border="1px solid"
							borderColor="gray.200"
							borderRadius="4px"
							px="5px"
							py="1px"
						>
							{part.slice(1, -1)}
						</Text>
					);
				}

				if (part.startsWith("**") && part.endsWith("**")) {
					return (
						<Text as="b" key={index} fontWeight="700">
							{part.slice(2, -2)}
						</Text>
					);
				}

				// Newlines are the one bit of layout these strings need — a
				// finding can carry a technical footnote that shouldn't run
				// into the explanation above it.
				return (
					<Fragment key={index}>
						{part.split("\n").map((line, lineIndex) => (
							<Fragment key={lineIndex}>
								{lineIndex > 0 && <br />}
								{line}
							</Fragment>
						))}
					</Fragment>
				);
			})}
		</>
	);
};

export default RichText;

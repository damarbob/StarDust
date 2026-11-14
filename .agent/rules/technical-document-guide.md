---
trigger: model_decision
description: Apply when writing a long technical document that people review, decide on or build from, such as a design document, a decision record, a blueprint or a specification, so a reader who was not in the discussion can follow it.
---

# Technical Document Guide

> Shared rule from [agent-rules](https://github.com/damarbob/agent-rules). Do not edit this copy. Change it in that repository and run sync. Repository-specific rules belong in a separate file in this folder.

How a long technical document is built so that a reader can follow it. The goal is a reader who was not in the discussion and may be tired, and who can still see the whole shape, check each rule and decide. It covers documents that people review, decide on or build from, such as design documents, decision records, blueprints and specifications. Punctuation, tone and the habits of generated text are outside this guide.

## Open with the picture

1. Start with one diagram that shows the whole feature or decision at once.
2. Under it, define the terms the diagram uses, in a few sentences.
3. Then list the decisions the reader is asked to approve, each pointing to the rule or section that states it.
4. Keep all three to one screen. A reader who stops there should know what they are approving.

The list is for a document someone approves. Don't add it, or a summary at the end, to a document nobody decides on.

```text
Accepting this blueprint means accepting that

- an order holds one shipping address, or up to 5 when several are allowed (S3)
- a customer without the Orders permission sees "Order 41" and cannot change it (W1, W2)
```

## Match the form to the content

Choose the form from what the content is.

| Content | Form |
| :-- | :-- |
| Several cases, each with a result | A table with the columns "When" and "Then". |
| Topics, and what holds for each | A table with the columns "Topic" and "What holds". |
| An order of checks or steps | A diagram. |
| A reason, or a trade-off | A paragraph. |

- A table heading says something. "Rule", "Description" and "Details" don't. A table with one row, or one that is a column of full sentences, is a list, so write a list.
- Alternate the forms, so that a quick scroll shows a picture, a list, a table and another picture. A page of paragraphs only, or of tables only, tires the reader before they start.
- Give each topic its own heading. The heading is what tells the reader the topic has changed, and no sentence is needed to announce it.

## One word per meaning

Pick one word for each concept and use it everywhere. Define it where it first appears in the document, not only in a glossary. When one word carries two meanings, rename one of them.

| Instead of | Write |
| :-- | :-- |
| "Delete" for moving to the trash and for removing for good | "Trash" and "delete for good" |
| "Key" for an API key and for a setting name | "API key" and "setting name" |

## Say it as you would say it

Every label, condition and diagram node should be the plain sentence you would say aloud. When the label and the sentence differ, the label hides something, so rewrite it.

| Instead of | Write |
| :-- | :-- |
| Is the order still valid? | Is every item in the order in stock? |
| Check the rest | Check that the remaining values are numbers |
| Handle hidden | Take out the values this person can't see |

## Give a reason

Add one clause of reason to any rule whose purpose isn't obvious, such as "so a forgotten secret can't publish an unsigned release". Skip it where the purpose is plain. Never invent a reason for a choice nobody has made. List that choice as undecided instead.

## Show values as values

A bare number or word in prose makes the reader guess what comes next. "The field stores 12" leaves the question "12 what".

| Instead of | Write |
| :-- | :-- |
| The field stores 12. | The field stores the id `12`. |
| The page says Entry 12. | The page says "Entry 12". |

Quote what a person sees on screen and put what is stored in code font. In a diagram label, where code font does not render, quote both.

## Diagrams

Draw diagrams top to bottom. One that runs left to right is wide, and on a narrow screen it shrinks until the text can't be read. Left to right is fine for three or four short nodes. Put the diagram beside the rules it explains.

## Analogies

An analogy is allowed where a reader would otherwise stall. It comes from everyday life that the reader pictures at once, never from another technical field, and it takes one sentence. Give the literal sentence and a real example first, then the analogy, labelled so the reader knows it is a picture and not a rule. Use one per hard idea, and cut it if it needs a second sentence.

- Instead of "It works like a foreign key on a join table."
- Write "In everyday terms, a cloakroom finds a coat by its ticket number, so the owner can change their name and the coat is still found."

## Rewrite, don't layer

To make a document easier to read, start again from the ideas. List what the reader must hold in mind, then write the least that carries it. Adding explanation around the old text makes the document longer and no easier to follow.

Don't cut rules to be short, because a specification has to stay complete. Cut the words around them, such as openers, restatements and repeated explanation. Count the words before and after, and report both, so the result can be judged.

## Before handing over

1. Read the opening alone. Does a reader know what they are approving?
2. Take each term. Does it keep one meaning?
3. Say each label, condition and node aloud. Is it the same sentence?
4. Read every table heading. Does it say something, and does any table have one row?
5. Does each rule that isn't obvious carry a reason, and is every reason a real one?
6. Are the values shown to people in quotes and the stored values in code font?
7. Do the diagrams run top to bottom?
8. If you rewrote, did you report the word counts before and after?

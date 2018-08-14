<?php
/* Copyright (c) 1998-2018 ILIAS open source, Extended GPL, see docs/LICENSE */

/**
 * Interface ilTermsOfServiceSequentialDocumentEvaluation
 * @author Michael Jansen <mjansen@databay.de>
 */
class ilTermsOfServiceSequentialDocumentEvaluation implements \ilTermsOfServiceDocumentEvaluation
{
	/** @var \ilTermsOfServiceDocumentCriteriaEvaluation */
	protected $evaluation;

	/** @var \ilObjUser */
	protected $user;

	/**
	 * @var \ilTermsOfServiceDocument[]|null
	 */
	protected $matchingDocuments = null;

	/**
	 * ilTermsOfServiceDocumentLogicalAndCriteriaEvaluation constructor.
	 * @param \ilTermsOfServiceDocumentCriteriaEvaluation $evaluation
	 * @param \ilObjUser $user
	 */
	public function __construct(
		\ilTermsOfServiceDocumentCriteriaEvaluation $evaluation,
		\ilObjUser $user
	) {
		$this->evaluation = $evaluation;
		$this->user = $user;
	}

	/**
	 * @return \ilTermsOfServiceSignableDocument[]
	 */
	protected function getMatchingDocuments(): array
	{
		if (null === $this->matchingDocuments) {
			$documents = \ilTermsOfServiceDocument::orderBy('sorting')->get();

			$this->matchingDocuments = [];
			foreach ($documents as $document) {
				if ($this->evaluation->evaluate($document)) {
					$this->matchingDocuments[] = $document;
				}
			}
		}

		return $this->matchingDocuments;
	}

	/**
	 * @inheritdoc
	 */
	public function getDocument(): \ilTermsOfServiceSignableDocument
	{
		$matchingDocuments = $this->getMatchingDocuments();
		if (count($matchingDocuments) > 0) {
			return $matchingDocuments[0];
		}

		throw new \ilTermsOfServiceNoSignableDocumentFoundException(sprintf(
			'Could not find any terms of service document for the passed user (id: %s|login: %s)',
			$this->user->getId(), $this->user->getLogin()
		));
	}

	/**
	 * @inheritdoc
	 */
	public function hasDocument(): bool 
	{
		return count($this->getMatchingDocuments()) > 0;
	}
}
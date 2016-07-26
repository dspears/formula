
#  Formula

## This is a portion of a web application that processes formulas extracted from requirements documents written in MS Word.

I developed this application to extract a set of mathematical formulas that describe desired system performance, 
from Microsoft Word requirements documents, and store them in a central database.  The application not only extracts 
the formulas, but parses them and recursively expands them in cases where a given formula is defined in terms of other simpler 
formulas.  By expanding the formulas we're able to identify all the individual performance metrics that factor into 
each formula.

![uploading a Word doc](https://github.com/dspears/formula/blob/master/doc/wordUpload.png)

With introduction of this tool, the community of users no longer have to search through a repository of Word documents,
and manually expand formula definitions.  Instead, users can view formulas by software release, compare how formulas
changed from one release to another, and download sets of formulas in Word, Excel, or XML formats.

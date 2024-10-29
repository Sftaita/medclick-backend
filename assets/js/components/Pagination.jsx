import React from 'react';

const Pagination = ({ currentPage, totalItems, onPageChanged }) => {
    
    const numberOfPages = Math.ceil(totalItems/30);
    const pages = [];

    for(let i = 1; i<=numberOfPages; i++){
        pages.push(i);
    }
   
    return ( 
    <>
        <div>
            <ul className="pagination">

                <li key={numberOfPages + 1} className={"page-item" + (currentPage == 1 && " disabled")}>
                    <button className="page-link"  aria-label="Previous" onClick={() => onPageChanged(currentPage - 1)}>
                        <span aria-hidden="true">&laquo;</span>
                    </button>
                </li>

                {(currentPage > 3) &&
                <li key={numberOfPages + 2} className="page-item disabled">
                    <button className="page-link"  aria-label="Previous" onClick={() => onPageChanged(1)}>
                        <span aria-hidden="true">...</span>
                    </button>
                </li>
                ||
                <div></div>
                }
                    
                {pages.map(page =>

                    ((currentPage - page) <= 2) & ((currentPage - page) >= -2) &&
                    <div key={page}>
                        <li key={page} className={"page-item" + (currentPage === page && " active")}>
                            <button className="page-link" onClick={() => onPageChanged(page)}>{page}</button>
                        </li>
                    </div>
                    ||
                    <div></div>
                )}

                { (numberOfPages-currentPage) >= 3 &&
                <li key={numberOfPages + 3} className="page-item disabled">
                    <button className="page-link"  aria-label="Previous" onClick={() => onPageChanged(numberOfPages)}>
                        <span aria-hidden="true">...</span>
                    </button>
                </li>
                ||
                <div></div>
                }    
                   
                <li key={numberOfPages + 4} className={"page-item" + (currentPage === numberOfPages && " disabled")}>
                    <button className="page-link"  aria-label="Previous" onClick={() => onPageChanged(currentPage + 1)}>
                        <span aria-hidden="true">&raquo;</span>
                    </button>
                </li>
            </ul>
        </div>
    </> 
    );
}
 
export default Pagination;